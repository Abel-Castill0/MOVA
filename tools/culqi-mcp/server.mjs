#!/usr/bin/env node
// MCP local (stdio) para administrar TÉCNICAMENTE la integración de Culqi de MOVA desde Claude Code.
// Sin dependencias (Node >= 18). Protocolo: JSON-RPC 2.0, un mensaje JSON por línea.
//
// Seguridad (todas verificadas en test.mjs):
//  - Las llaves se leen del .env local / entorno; NUNCA se devuelven, ni se registran. Toda salida
//    pasa por redact().
//  - Lecturas (GET): siempre disponibles con llaves test; con llaves live solo si CULQI_MCP_ALLOW_LIVE_READ=true.
//  - Escrituras: SOLO con CULQI_MCP_ALLOW_WRITES=true Y llaves sk_test_/pk_test_ Y CULQI_ENV=test.
//    Con llaves live las escrituras están bloqueadas sin excepción (no hay variable que las habilite).
//  - Importe de escrituras topado por CULQI_SANDBOX_MAX_AMOUNT_MINOR (céntimos, 1000 por defecto).
//  - Solo tarjetas de prueba PUBLICADAS por Culqi, elegidas por nombre de escenario (el modelo nunca
//    maneja un PAN). No hay herramienta para cargos/devoluciones de producción.
//  - Idempotencia de escrituras: cada llamada exige un `request_id`. Se anota la intención en un ledger
//    (jsonl, append-only) ANTES de hablar con Culqi; un request_id repetido devuelve el resultado
//    guardado sin llamar a Culqi, y uno "iniciado sin resultado" (corte a mitad) se rechaza en vez de
//    reenviarse. Los POST jamás se reintentan.
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import readline from 'node:readline';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..', '..');
const VERSION = '0.1.0';

// ── Configuración ─────────────────────────────────────────────────────────

function loadEnv() {
  const env = {};
  const file = process.env.MOVA_ENV_FILE ? path.resolve(ROOT, process.env.MOVA_ENV_FILE) : path.join(ROOT, '.env');
  try {
    for (const line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
      const m = /^(CULQI_[A-Z_]+)=(.*)$/.exec(line);
      if (m) env[m[1]] = m[2].trim().replace(/^"|"$/g, '');
    }
  } catch { /* sin .env: solo entorno */ }
  for (const [k, v] of Object.entries(process.env)) if (k.startsWith('CULQI_') && v !== undefined) env[k] = v;
  return env;
}

const ENV = loadEnv();
const SECRET = ENV.CULQI_SECRET_KEY || ENV.CULQI_PRIVATE_KEY || '';
const PUBLIC = ENV.CULQI_PUBLIC_KEY || '';
const BASE = (ENV.CULQI_BASE_URL || 'https://api.culqi.com').replace(/\/$/, '');
const SECURE = (ENV.CULQI_SECURE_URL || 'https://secure.culqi.com').replace(/\/$/, '');
const KEY_MODE = (k, kind) => { const m = new RegExp(`^${kind}_(test|live)_[A-Za-z0-9]{8,}$`).exec(k); return m ? m[1] : null; };
const SECRET_MODE = KEY_MODE(SECRET, 'sk');
const PUBLIC_MODE = KEY_MODE(PUBLIC, 'pk');
const DECLARED = (ENV.CULQI_ENV || 'test').toLowerCase();
const MAX_MINOR = Math.max(1, Number(ENV.CULQI_SANDBOX_MAX_AMOUNT_MINOR || 1000));
const WRITES_FLAG = String(ENV.CULQI_MCP_ALLOW_WRITES || '').toLowerCase() === 'true';
const LIVE_READ_FLAG = String(ENV.CULQI_MCP_ALLOW_LIVE_READ || '').toLowerCase() === 'true';
const LEDGER = process.env.CULQI_MCP_LEDGER || path.join(HERE, '.state', 'ledger.jsonl');

function configProblems() {
  const p = [];
  if (!SECRET) p.push('CULQI_SECRET_KEY no configurada');
  else if (!SECRET_MODE) p.push('CULQI_SECRET_KEY no tiene formato sk_test_/sk_live_ (¿valor de ejemplo?)');
  if (PUBLIC && !PUBLIC_MODE) p.push('CULQI_PUBLIC_KEY no tiene formato pk_test_/pk_live_');
  if (SECRET_MODE && PUBLIC_MODE && SECRET_MODE !== PUBLIC_MODE) p.push('las llaves mezclan modos test/live');
  if (SECRET_MODE && DECLARED !== SECRET_MODE) p.push(`CULQI_ENV=${DECLARED} no coincide con el modo de la llave (${SECRET_MODE})`);
  // La llave secreta solo viaja a los hosts oficiales de Culqi (el doble de prueba exige CULQI_MCP_TEST_MODE=1).
  if (process.env.CULQI_MCP_TEST_MODE !== '1') {
    const official = (u, host) => { try { const x = new URL(u); return x.protocol === 'https:' && x.hostname === host; } catch { return false; } };
    if (!official(BASE, 'api.culqi.com')) p.push('CULQI_BASE_URL debe ser https://api.culqi.com');
    if (!official(SECURE, 'secure.culqi.com')) p.push('CULQI_SECURE_URL debe ser https://secure.culqi.com');
  }
  return p;
}
const TEST_OK = () => configProblems().length === 0 && SECRET_MODE === 'test' && DECLARED === 'test';
const WRITES_ENABLED = () => WRITES_FLAG && TEST_OK() && PUBLIC_MODE === 'test';

// ── Redacción y proyecciones ──────────────────────────────────────────────

const SECRET_RE = /\b(?:sk|pk)_(?:test|live)_[A-Za-z0-9]{6,}\b|Bearer\s+[A-Za-z0-9._-]{12,}/g;
export function redact(text) {
  let out = String(text).replace(SECRET_RE, '[REDACTADO]');
  for (const s of [SECRET, PUBLIC, ENV.CULQI_WEBHOOK_SECRET, ENV.CULQI_RSA_PUBLIC_KEY].filter((x) => x && x.length >= 8)) out = out.split(s).join('[REDACTADO]');
  return out;
}
const maskEmail = (e) => (typeof e === 'string' && e.includes('@') ? e.replace(/^(.).*(@.*)$/, '$1***$2') : e ?? null);
const iso = (ms) => (Number.isFinite(Number(ms)) && Number(ms) > 0 ? new Date(Number(ms) > 1e12 ? Number(ms) : Number(ms) * 1000).toISOString() : null);

/** Misma lógica que App\Payment\Culqi\CulqiChargeMapper::normalize() (fail-closed). */
export function normalizeCharge(c) {
  const amount = Number.isInteger(c?.amount) ? c.amount : null;
  const refunded = Number(c?.amount_refunded ?? 0) || 0;
  const type = c?.outcome?.type, code = c?.outcome?.code;
  if (!amount || amount <= 0) return 'review';
  if (refunded > 0) return refunded >= amount ? 'reversed' : 'review';
  if (c?.dispute === true) return 'review';
  const approved = (type === 'venta_exitosa' && code === 'AUT0000') || (code === 'succesfull_charge' && c?.paid === true);
  if (approved && c?.capture !== false) return 'paid';
  if (!approved && (['operacion_denegada', 'venta_denegada'].includes(type) || code === 'card_declined')) return 'failed';
  return 'review';
}

const projCharge = (c) => ({
  id: c.id, state_mova: normalizeCharge(c), amount_minor: c.amount, amount_refunded_minor: Number(c.amount_refunded ?? 0), currency: c.currency_code,
  paid_flag: c.paid, capture: c.capture, dispute: c.dispute, duplicated: c.duplicated,
  outcome: c.outcome ? { type: c.outcome.type, code: c.outcome.code, decline_code: c.outcome.decline_code ?? null } : null,
  email: maskEmail(c.email), source: c.source ? { type: c.source.type, brand: c.source.iin?.card_brand ?? null, last_four: c.source.last_four ?? null } : null,
  created_at: iso(c.creation_date), metadata: c.metadata ?? {},
  mova: { payment_order_id: c.metadata?.payment_order_id ?? null, recharge_request_id: c.metadata?.recharge_request_id ?? null },
});
const projRefund = (r) => ({ id: r.id, charge_id: r.charge_id, amount_minor: r.amount, reason: r.reason, status: r.status, created_at: iso(r.creation_date), metadata: r.metadata ?? {} });
const projOrder = (o) => ({ id: o.id, state: o.state, amount_minor: o.amount, currency: o.currency_code, order_number: o.order_number, payment_code: o.payment_code, created_at: iso(o.creation_date), expires_at: iso(o.expiration_date), paid_at: iso(o.paid_at), metadata: o.metadata ?? {} });
const projEvent = (e) => ({ id: e.id, type: e.type, created_at: iso(e.creation_date), status_code: e.status_code ?? null, data_id: (() => { try { const d = typeof e.data === 'string' ? JSON.parse(e.data) : e.data; return d?.id ?? null; } catch { return null; } })() });
const projDeposit = (d) => ({ id: d.id, amount_minor: d.amount, currency: d.currency_code, status: d.status ?? null, created_at: iso(d.creation_date) });

// ── HTTP hacia Culqi ──────────────────────────────────────────────────────

async function call(method, url, { bearer, body, retries = 0 } = {}) {
  for (let attempt = 0; ; attempt++) {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), 15000);
    try {
      const r = await fetch(url, { method, signal: ctrl.signal, headers: { Authorization: `Bearer ${bearer}`, Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) }, body: body ? JSON.stringify(body) : undefined });
      const json = await r.json().catch(() => null);
      return { status: r.status, json };
    } catch (e) {
      if (method === 'GET' && attempt < retries) continue; // los POST jamás se reintentan
      return { status: 0, json: null, transport: e?.name ?? 'Error' };
    } finally {
      clearTimeout(timer);
    }
  }
}
const GET = (p, q = {}) => call('GET', `${BASE}${p}${Object.keys(q).length ? '?' + new URLSearchParams(q) : ''}`, { bearer: SECRET, retries: 1 });

// ── Ledger de idempotencia (append-only) ──────────────────────────────────

function ledgerRead() {
  try { return fs.readFileSync(LEDGER, 'utf8').split('\n').filter(Boolean).map((l) => JSON.parse(l)); } catch { return []; }
}
function ledgerAppend(rec) {
  fs.mkdirSync(path.dirname(LEDGER), { recursive: true });
  const fd = fs.openSync(LEDGER, 'a');
  try { fs.writeSync(fd, JSON.stringify({ at: new Date().toISOString(), ...rec }) + '\n'); fs.fsyncSync(fd); } finally { fs.closeSync(fd); }
}
/** Sección crítica entre procesos mediante un archivo de bloqueo (creación exclusiva). */
function withLedgerLock(fn) {
  const lock = `${LEDGER}.lock`;
  fs.mkdirSync(path.dirname(LEDGER), { recursive: true });
  const deadline = Date.now() + 3000;
  for (;;) {
    let fd;
    try {
      fd = fs.openSync(lock, 'wx');
    } catch (e) {
      if (e.code !== 'EEXIST') throw e;
      try { if (Date.now() - fs.statSync(lock).mtimeMs > 10000) fs.rmSync(lock, { force: true }); } catch { /* otro proceso lo liberó */ }
      if (Date.now() > deadline) throw new ToolError('El ledger de idempotencia está ocupado por otro proceso; reintenta en unos segundos.');
      Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 50);
      continue;
    }
    try {
      return fn();
    } finally {
      fs.closeSync(fd);
      fs.rmSync(lock, { force: true });
    }
  }
}
const hashParams = (o) => crypto.createHash('sha256').update(JSON.stringify(o)).digest('hex').slice(0, 16);

/** Ejecuta una escritura una sola vez por request_id. */
async function idempotentWrite(tool, requestId, params, exec) {
  if (!/^[A-Za-z0-9_.:-]{8,64}$/.test(requestId ?? '')) throw new ToolError('request_id obligatorio: 8-64 caracteres [A-Za-z0-9_.:-] (identificador de idempotencia que eliges tú).');
  const hash = hashParams(params);
  // Comprobar + anotar "started" es atómico entre procesos MCP (archivo de bloqueo): dos
  // procesos con el mismo request_id no pueden pasar ambos.
  const gate = withLedgerLock(() => {
    const history = ledgerRead().filter((r) => r.request_id === requestId);
    const done = history.find((r) => r.status === 'done');
    if (done) {
      if (done.params_hash !== hash) throw new ToolError(`request_id ya usado con parámetros distintos (${done.tool}). Usa un request_id nuevo.`);
      return { replay: done.result };
    }
    if (history.some((r) => r.status === 'started')) {
      throw new ToolError(`request_id ${requestId} quedó "iniciado" sin resultado (corte a mitad o en curso en otro proceso). NO se reenvía para no duplicar el cobro: busca el cargo con culqi_list_charges y metadata.mcp_request_id=${requestId}, o usa un request_id nuevo tras comprobarlo.`);
    }
    ledgerAppend({ request_id: requestId, tool, params_hash: hash, status: 'started' });
    return { replay: null };
  });
  if (gate.replay) return { replayed: true, ...gate.replay };
  const result = await exec();
  ledgerAppend({ request_id: requestId, tool, params_hash: hash, status: 'done', result });
  return { replayed: false, ...result };
}

class ToolError extends Error {}

// ── Herramientas ──────────────────────────────────────────────────────────

/** Tarjetas de prueba PUBLICADAS en docs.culqi.com/es/documentacion/pagos-online/tarjetas-de-prueba */
const TEST_CARDS = {
  approved_visa_debit: { card_number: '4111111111111111', cvv: '123', expiration_month: '09', expiration_year: '2030' },
  approved_mastercard: { card_number: '5111111111111118', cvv: '039', expiration_month: '12', expiration_year: '2030' },
  declined_insufficient_funds: { card_number: '4000040000000008', cvv: '295', expiration_month: '03', expiration_year: '2030' },
  declined_stolen_card: { card_number: '4000020000000000', cvv: '354', expiration_month: '10', expiration_year: '2030' },
  declined_lost_card: { card_number: '4000030000000009', cvv: '360', expiration_month: '08', expiration_year: '2030' },
  declined_incorrect_cvv: { card_number: '5400020000000003', cvv: '203', expiration_month: '07', expiration_year: '2030' },
};

const need = (cond, msg) => { if (!cond) throw new ToolError(msg); };
const readGuard = () => {
  const p = configProblems();
  need(p.length === 0, `Configuración de Culqi no válida: ${p.join('; ')}. Ejecuta culqi_status.`);
  need(SECRET_MODE === 'test' || LIVE_READ_FLAG, 'Llaves LIVE detectadas: las lecturas live están desactivadas (CULQI_MCP_ALLOW_LIVE_READ=true para habilitarlas; las escrituras live no existen).');
};
const writeGuard = () => {
  need(WRITES_FLAG, 'Escrituras desactivadas (por defecto). Habilítalas solo para sandbox con CULQI_MCP_ALLOW_WRITES=true en el entorno del servidor MCP.');
  need(WRITES_ENABLED(), 'Escrituras bloqueadas: exigen CULQI_ENV=test y llaves pk_test_/sk_test_ coherentes. Con llaves live las escrituras NUNCA se permiten.');
};
const cap = (n, lo, hi) => Math.max(lo, Math.min(hi, Number(n) || lo));
const amountGuard = (n) => { need(Number.isInteger(n) && n >= 100 && n <= MAX_MINOR, `amount_minor debe ser entero entre 100 y ${MAX_MINOR} (tope de sandbox, CULQI_SANDBOX_MAX_AMOUNT_MINOR).`); };
const idGuard = (id, prefix) => need(typeof id === 'string' && new RegExp(`^${prefix}_(test|live)_[A-Za-z0-9]{6,}$`).test(id), `id inválido (se espera ${prefix}_test_…).`);
const apiResult = (r, project) => {
  if (r.json?.object === 'error' || r.status >= 400 || r.status === 0) {
    return { ok: false, http_status: r.status, transport_error: r.transport ?? null, error: r.json ? { type: r.json.type, code: r.json.code ?? null, charge_id: r.json.charge_id ?? null, message: r.json.merchant_message ?? r.json.user_message ?? null } : null };
  }
  return { ok: true, http_status: r.status, data: project(r.json) };
};
const listResult = (r, project) => (r.status === 200 && Array.isArray(r.json?.data) ? { ok: true, http_status: 200, count: r.json.data.length, data: r.json.data.map(project) } : apiResult(r, project));
const pick = (args, keys) => Object.fromEntries(keys.filter((k) => args[k] !== undefined && args[k] !== '').map((k) => [k, args[k]]));

const TOOLS = {
  culqi_status: {
    description: 'Estado de la configuración de Culqi (modo, formato de llaves, escrituras habilitadas) sin revelar secretos. Empieza por aquí.',
    inputSchema: { type: 'object', properties: {}, additionalProperties: false },
    run: async () => ({ ok: configProblems().length === 0, problems: configProblems(), declared_env: DECLARED, secret_key: SECRET ? `sk_${SECRET_MODE ?? 'formato-invalido'}` : null, public_key: PUBLIC ? `pk_${PUBLIC_MODE ?? 'formato-invalido'}` : null, writes_enabled: WRITES_ENABLED(), writes_flag: WRITES_FLAG, sandbox_max_amount_minor: MAX_MINOR, base_url: BASE, server_version: VERSION }),
  },
  culqi_get_charge: {
    description: 'Consulta un cargo (chr_…) y su estado traducido a los estados de MOVA (paid|failed|reversed|review). Solo lectura.',
    inputSchema: { type: 'object', properties: { id: { type: 'string' } }, required: ['id'], additionalProperties: false },
    run: async ({ id }) => { readGuard(); idGuard(id, 'chr'); return apiResult(await GET(`/v2/charges/${encodeURIComponent(id)}`), projCharge); },
  },
  culqi_list_charges: {
    description: 'Lista cargos recientes (máx. 25) con filtros opcionales. Solo lectura.',
    inputSchema: { type: 'object', properties: { limit: { type: 'integer', maximum: 25 }, email: { type: 'string' }, amount_minor: { type: 'integer' }, from_unix: { type: 'integer' }, to_unix: { type: 'integer' } }, additionalProperties: false },
    run: async (a) => { readGuard(); const q = { limit: cap(a.limit ?? 10, 1, 25), ...pick({ email: a.email, amount: a.amount_minor, creation_date_from: a.from_unix, creation_date_to: a.to_unix }, ['email', 'amount', 'creation_date_from', 'creation_date_to']) }; return listResult(await GET('/v2/charges', q), projCharge); },
  },
  culqi_get_refund: {
    description: 'Consulta una devolución (ref_…). Solo lectura.',
    inputSchema: { type: 'object', properties: { id: { type: 'string' } }, required: ['id'], additionalProperties: false },
    run: async ({ id }) => { readGuard(); idGuard(id, 'ref'); return apiResult(await GET(`/v2/refunds/${encodeURIComponent(id)}`), projRefund); },
  },
  culqi_list_refunds: {
    description: 'Lista devoluciones recientes (máx. 25). Solo lectura.',
    inputSchema: { type: 'object', properties: { limit: { type: 'integer', maximum: 25 }, status: { type: 'string', enum: ['Completa', 'Pendiente', 'Rechazado'] } }, additionalProperties: false },
    run: async (a) => { readGuard(); return listResult(await GET('/v2/refunds', { limit: cap(a.limit ?? 10, 1, 25), ...pick(a, ['status']) }), projRefund); },
  },
  culqi_get_order: {
    description: 'Consulta una orden de pago (ord_…). Solo lectura.',
    inputSchema: { type: 'object', properties: { id: { type: 'string' } }, required: ['id'], additionalProperties: false },
    run: async ({ id }) => { readGuard(); idGuard(id, 'ord'); return apiResult(await GET(`/v2/orders/${encodeURIComponent(id)}`), projOrder); },
  },
  culqi_list_orders: {
    description: 'Lista órdenes recientes (máx. 25). Solo lectura.',
    inputSchema: { type: 'object', properties: { limit: { type: 'integer', maximum: 25 } }, additionalProperties: false },
    run: async (a) => { readGuard(); return listResult(await GET('/v2/orders', { limit: cap(a.limit ?? 10, 1, 25) }), projOrder); },
  },
  culqi_get_event: {
    description: 'Consulta un evento (evt_…), p. ej. el que figura en un webhook recibido. Solo lectura.',
    inputSchema: { type: 'object', properties: { id: { type: 'string' } }, required: ['id'], additionalProperties: false },
    run: async ({ id }) => { readGuard(); idGuard(id, 'evt'); return apiResult(await GET(`/v2/events/${encodeURIComponent(id)}`), projEvent); },
  },
  culqi_list_events: {
    description: 'Lista eventos recientes (máx. 25), filtrables por tipo (p. ej. charge.creation.succeeded). Solo lectura.',
    inputSchema: { type: 'object', properties: { limit: { type: 'integer', maximum: 25 }, type: { type: 'string' } }, additionalProperties: false },
    run: async (a) => { readGuard(); return listResult(await GET('/v2/events', { limit: cap(a.limit ?? 10, 1, 25), ...pick(a, ['type']) }), projEvent); },
  },
  culqi_list_deposits: {
    description: 'Conciliación: lista abonos (depósitos) del estado de cuenta en un rango de fechas (por defecto los últimos 30 días, PEN). Solo lectura; requiere comercio con estados de cuenta activos.',
    inputSchema: { type: 'object', properties: { currency_code: { type: 'string', enum: ['PEN', 'USD'] }, from_unix: { type: 'integer' }, to_unix: { type: 'integer' } }, additionalProperties: false },
    run: async (a) => {
      readGuard();
      const now = Math.floor(Date.now() / 1000);
      const r = await GET('/v2/balance/deposits', { currency_code: a.currency_code ?? 'PEN', date_from: a.from_unix ?? now - 30 * 86400, date_to: a.to_unix ?? now });
      if (r.status !== 200) return apiResult(r, projDeposit);
      const rows = Array.isArray(r.json) ? r.json : r.json?.data;
      return Array.isArray(rows) ? { ok: true, http_status: 200, count: rows.length, data: rows.map(projDeposit) } : { ok: true, http_status: 200, count: 0, data: [], response_keys: Object.keys(r.json ?? {}) };
    },
  },
  culqi_reconcile_report: {
    description: 'Resumen de conciliación de los últimos cargos: cuántos están paid/failed/reversed/review según las reglas de MOVA y cuáles carecen de metadata de MOVA. Solo lectura.',
    inputSchema: { type: 'object', properties: { limit: { type: 'integer', maximum: 25 } }, additionalProperties: false },
    run: async (a) => {
      readGuard();
      const r = await GET('/v2/charges', { limit: cap(a.limit ?? 25, 1, 25) });
      if (r.status !== 200) return apiResult(r, projCharge);
      const rows = r.json.data.map(projCharge);
      const by = rows.reduce((acc, c) => ({ ...acc, [c.state_mova]: (acc[c.state_mova] ?? 0) + 1 }), {});
      return { ok: true, total: rows.length, by_state_mova: by, without_mova_metadata: rows.filter((c) => !c.mova.payment_order_id).map((c) => c.id), review: rows.filter((c) => c.state_mova === 'review').map((c) => c.id) };
    },
  },
  culqi_sandbox_charge: {
    description: 'ESCRITURA SANDBOX: crea un cargo de PRUEBA con una tarjeta de prueba publicada (por nombre de escenario). Idempotente por request_id; importe topado. Desactivada salvo CULQI_MCP_ALLOW_WRITES=true con llaves test.',
    inputSchema: { type: 'object', properties: { request_id: { type: 'string', description: '8-64 chars; identificador de idempotencia que eliges tú' }, scenario: { type: 'string', enum: Object.keys(TEST_CARDS) }, amount_minor: { type: 'integer', description: 'céntimos PEN, 100..tope' }, email: { type: 'string' } }, required: ['request_id', 'scenario', 'amount_minor'], additionalProperties: false },
    run: async (a) => {
      writeGuard(); amountGuard(a.amount_minor);
      const card = TEST_CARDS[a.scenario]; need(card, `scenario desconocido: ${a.scenario}`);
      const email = a.email && /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(a.email) ? a.email : 'sandbox-mcp@example.com';
      return idempotentWrite('culqi_sandbox_charge', a.request_id, { scenario: a.scenario, amount_minor: a.amount_minor, email }, async () => {
        const tok = await call('POST', `${SECURE}/v2/tokens`, { bearer: PUBLIC, body: { ...card, email } });
        if (tok.status !== 201 || !tok.json?.id) return { ok: false, stage: 'token', http_status: tok.status, error: tok.json?.merchant_message ?? tok.transport ?? null };
        const ch = await call('POST', `${BASE}/v2/charges`, { bearer: SECRET, body: { amount: a.amount_minor, currency_code: 'PEN', email, source_id: tok.json.id, capture: true, description: 'MOVA sandbox MCP', metadata: { mcp_request_id: a.request_id, source: 'mova-culqi-mcp' } } });
        if (ch.status === 0 || ch.status >= 500 || ch.status === 408 || ch.status === 429) return { ok: false, stage: 'charge', uncertain: true, http_status: ch.status, hint: `Resultado incierto: NO se reintenta. Busca el cargo con culqi_list_charges (metadata.mcp_request_id=${a.request_id}).` };
        return { stage: 'charge', ...apiResult(ch, projCharge) };
      });
    },
  },
  culqi_sandbox_refund: {
    description: 'ESCRITURA SANDBOX: devuelve (total o parcial) un cargo de PRUEBA (chr_test_…). Idempotente por request_id; importe topado. Desactivada salvo CULQI_MCP_ALLOW_WRITES=true con llaves test.',
    inputSchema: { type: 'object', properties: { request_id: { type: 'string' }, charge_id: { type: 'string' }, amount_minor: { type: 'integer' }, reason: { type: 'string', enum: ['duplicado', 'fraudulento', 'solicitud_comprador'] } }, required: ['request_id', 'charge_id', 'amount_minor', 'reason'], additionalProperties: false },
    run: async (a) => {
      writeGuard(); amountGuard(a.amount_minor);
      need(/^chr_test_[A-Za-z0-9]{6,}$/.test(a.charge_id ?? ''), 'charge_id debe ser un cargo de PRUEBA (chr_test_…).');
      need(['duplicado', 'fraudulento', 'solicitud_comprador'].includes(a.reason), 'reason inválido.');
      return idempotentWrite('culqi_sandbox_refund', a.request_id, { charge_id: a.charge_id, amount_minor: a.amount_minor, reason: a.reason }, async () => {
        const cur = await GET(`/v2/charges/${encodeURIComponent(a.charge_id)}`);
        if (cur.status !== 200) return { ok: false, stage: 'precheck', http_status: cur.status };
        const remaining = (cur.json.amount ?? 0) - Number(cur.json.amount_refunded ?? 0);
        if (a.amount_minor > remaining) return { ok: false, stage: 'precheck', error: `El importe (${a.amount_minor}) supera lo devolvible (${remaining}).` };
        const rf = await call('POST', `${BASE}/v2/refunds`, { bearer: SECRET, body: { amount: a.amount_minor, charge_id: a.charge_id, reason: a.reason, metadata: { mcp_request_id: a.request_id } } });
        if (rf.status === 0 || rf.status >= 500 || rf.status === 408 || rf.status === 429) return { ok: false, stage: 'refund', uncertain: true, http_status: rf.status, hint: `Resultado incierto: NO se reintenta. Consulta culqi_list_refunds / culqi_get_charge antes de decidir.` };
        return { stage: 'refund', ...apiResult(rf, projRefund) };
      });
    },
  },
};

// ── Protocolo MCP (JSON-RPC por stdio) ────────────────────────────────────

const SUPPORTED = ['2025-06-18', '2025-03-26', '2024-11-05'];
const send = (obj) => process.stdout.write(JSON.stringify(obj) + '\n');
const reply = (id, result) => send({ jsonrpc: '2.0', id, result });
const fail = (id, code, message) => send({ jsonrpc: '2.0', id, error: { code, message } });

async function handle(msg) {
  const { id, method, params } = msg;
  const isNotification = id === undefined || id === null;
  try {
    switch (method) {
      case 'initialize':
        return reply(id, { protocolVersion: SUPPORTED.includes(params?.protocolVersion) ? params.protocolVersion : SUPPORTED[0], capabilities: { tools: {} }, serverInfo: { name: 'mova-culqi', version: VERSION }, instructions: 'Administración técnica de Culqi para MOVA. Lecturas libres (modo test); escrituras solo sandbox, con request_id de idempotencia y tope de importe. Empieza con culqi_status.' });
      case 'ping':
        return reply(id, {});
      case 'tools/list':
        return reply(id, { tools: Object.entries(TOOLS).map(([name, t]) => ({ name, description: t.description, inputSchema: t.inputSchema })) });
      case 'tools/call': {
        const tool = TOOLS[params?.name];
        if (!tool) return fail(id, -32602, `Herramienta desconocida: ${params?.name}`);
        try {
          const out = await tool.run(params?.arguments ?? {});
          let text = redact(JSON.stringify(out, null, 2));
          if (text.length > 20000) text = text.slice(0, 20000) + '\n…[truncado]';
          return reply(id, { content: [{ type: 'text', text }], isError: out?.ok === false });
        } catch (e) {
          return reply(id, { content: [{ type: 'text', text: redact(e instanceof ToolError ? e.message : `Error interno: ${e?.name ?? 'Error'}`) }], isError: true });
        }
      }
      default:
        if (isNotification) return; // notifications/initialized, etc.
        return fail(id, -32601, `Método no soportado: ${method}`);
    }
  } catch (e) {
    if (!isNotification) fail(id, -32603, redact(e?.message ?? 'error interno'));
  }
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const rl = readline.createInterface({ input: process.stdin });
  rl.on('line', (line) => {
    if (!line.trim()) return;
    let msg;
    try { msg = JSON.parse(line); } catch { return fail(null, -32700, 'JSON inválido'); }
    handle(msg);
  });
}
