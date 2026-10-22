// Autotest del MCP de Culqi: `node --test tools/culqi-mcp/test.mjs`.
// 100 % local: un servidor HTTP falso hace de Culqi; no se usa red real, ni llaves reales, ni el .env.
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { after, before, describe, test } from 'node:test';
import { fileURLToPath } from 'node:url';

const SERVER = path.join(path.dirname(fileURLToPath(import.meta.url)), 'server.mjs');
const FAKE_SK = 'sk_test_MockMockMock1234';
const FAKE_PK = 'pk_test_MockMockMock1234';

// ── Culqi falso ───────────────────────────────────────────────────────────
const calls = { tokens: 0, charges: 0, refunds: 0 };
let mock, mockUrl;

const success = (over = {}) => ({ object: 'charge', id: 'chr_test_PAIDPAIDPAID01', amount: 500, amount_refunded: 0, currency_code: 'PEN', email: 'payer@example.com', capture: true, paid: false, dispute: false, duplicated: false, outcome: { type: 'venta_exitosa', code: 'AUT0000' }, metadata: { payment_order_id: '7', recharge_request_id: '7' }, source: { type: 'card', last_four: '1111', iin: { card_brand: 'Visa' } }, creation_date: 1792636180460, ...over });
const declined = { ...success({ id: 'chr_test_DECLINEDDECL01', outcome: { type: 'operacion_denegada', code: 'DNGE0015', decline_code: 'insufficient_funds' } }) };

before(async () => {
  mock = http.createServer((req, res) => {
    let raw = '';
    req.on('data', (c) => (raw += c));
    req.on('end', () => {
      const url = new URL(req.url, 'http://x');
      const body = raw ? JSON.parse(raw) : {};
      const json = (status, obj) => { res.writeHead(status, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(obj)); };
      if (req.method === 'POST' && url.pathname === '/v2/tokens') {
        calls.tokens++;
        return json(201, { object: 'token', id: body.card_number === '4000040000000008' ? 'tkn_test_DECLINEDTOKEN1' : 'tkn_test_APPROVEDTOKEN1' });
      }
      if (req.method === 'POST' && url.pathname === '/v2/charges') {
        calls.charges++;
        if (body.amount === 777) return req.socket.destroy(); // corte de red a mitad de la escritura
        if (body.source_id === 'tkn_test_DECLINEDTOKEN1') return json(400, { object: 'error', type: 'operacion_denegada', charge_id: declined.id, code: 'DNGE0015', decline_code: 'insufficient_funds', merchant_message: `Fondos insuficientes (${FAKE_SK})` });
        return json(201, success({ amount: body.amount, metadata: body.metadata }));
      }
      if (req.method === 'POST' && url.pathname === '/v2/refunds') {
        calls.refunds++;
        return json(201, { object: 'refund', id: 'ref_test_REFUNDREFUND01', charge_id: body.charge_id, amount: body.amount, reason: body.reason, status: 'Completa' });
      }
      if (req.method === 'GET' && url.pathname === '/v2/charges') return json(200, { data: [success(), declined, success({ id: 'chr_test_PARTIALPARTIAL1', amount_refunded: 100 })] });
      if (req.method === 'GET' && url.pathname === '/v2/charges/chr_test_PAIDPAIDPAID01') return json(200, success());
      if (req.method === 'GET' && url.pathname === '/v2/charges/chr_test_DECLINEDDECL01') return json(200, declined);
      if (req.method === 'GET' && url.pathname === '/v2/charges/chr_test_BADBADBADBAD01') return json(401, { object: 'error', type: 'authentication_error', merchant_message: `llave inválida ${FAKE_SK}` });
      if (req.method === 'GET' && url.pathname === '/v2/events') return json(200, { data: [{ id: 'evt_test_EVENTEVENT01', type: 'charge.creation.succeeded', creation_date: 1792636180, data: JSON.stringify({ id: 'chr_test_PAIDPAIDPAID01' }) }] });
      return json(404, { object: 'error', type: 'resource_error' });
    });
  });
  await new Promise((r) => mock.listen(0, '127.0.0.1', r));
  mockUrl = `http://127.0.0.1:${mock.address().port}`;
});
after(() => mock.close());

// ── Cliente MCP mínimo ────────────────────────────────────────────────────
function startServer(extraEnv = {}) {
  const ledger = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'culqi-mcp-')), 'ledger.jsonl');
  const env = { PATH: process.env.PATH, SystemRoot: process.env.SystemRoot, MOVA_ENV_FILE: 'no-existe.env', CULQI_ENV: 'test', CULQI_SECRET_KEY: FAKE_SK, CULQI_PUBLIC_KEY: FAKE_PK, CULQI_BASE_URL: mockUrl, CULQI_SECURE_URL: mockUrl, CULQI_MCP_TEST_MODE: '1', CULQI_MCP_LEDGER: ledger, ...extraEnv };
  const child = spawn(process.execPath, [SERVER], { env, stdio: ['pipe', 'pipe', 'pipe'] });
  const pending = new Map();
  let buf = '';
  child.stdout.on('data', (d) => {
    buf += d;
    let i;
    while ((i = buf.indexOf('\n')) >= 0) {
      const line = buf.slice(0, i); buf = buf.slice(i + 1);
      if (!line.trim()) continue;
      const m = JSON.parse(line);
      pending.get(m.id)?.(m);
    }
  });
  let n = 0;
  const rpc = (method, params) => new Promise((resolve) => { const id = ++n; pending.set(id, resolve); child.stdin.write(JSON.stringify({ jsonrpc: '2.0', id, method, params }) + '\n'); });
  const tool = async (name, args = {}) => {
    const r = await rpc('tools/call', { name, arguments: args });
    const text = r.result?.content?.[0]?.text ?? '';
    let data = null; try { data = JSON.parse(text); } catch { /* texto plano de error */ }
    return { raw: r, text, data, isError: r.result?.isError === true };
  };
  return { rpc, tool, ledger, child, stop: () => child.kill() };
}

// ── Pruebas ───────────────────────────────────────────────────────────────
describe('protocolo y superficie', () => {
  test('initialize + tools/list: solo herramientas de lectura y 2 de sandbox; ninguna de producción', async () => {
    const s = startServer();
    const init = await s.rpc('initialize', { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 't', version: '1' } });
    assert.equal(init.result.serverInfo.name, 'mova-culqi');
    const list = await s.rpc('tools/list');
    const names = list.result.tools.map((t) => t.name);
    for (const n of ['culqi_status', 'culqi_get_charge', 'culqi_list_charges', 'culqi_get_refund', 'culqi_list_refunds', 'culqi_get_order', 'culqi_list_orders', 'culqi_get_event', 'culqi_list_events', 'culqi_list_deposits', 'culqi_reconcile_report', 'culqi_sandbox_charge', 'culqi_sandbox_refund']) assert.ok(names.includes(n), n);
    assert.ok(names.filter((n) => /charge|refund/.test(n) && n.includes('create')).length === 0);
    assert.ok(list.result.tools.every((t) => t.inputSchema.type === 'object'));
    s.stop();
  });

  test('herramienta desconocida, método desconocido y JSON inválido devuelven errores JSON-RPC', async () => {
    const s = startServer();
    assert.equal((await s.rpc('tools/call', { name: 'culqi_drop_everything', arguments: {} })).error.code, -32602);
    assert.equal((await s.rpc('nope')).error.code, -32601);
    s.stop();
  });
});

describe('lecturas y secretos', () => {
  test('culqi_status describe el modo sin revelar las llaves', async () => {
    const s = startServer();
    const r = await s.tool('culqi_status');
    assert.equal(r.data.ok, true);
    assert.equal(r.data.secret_key, 'sk_test');
    assert.equal(r.data.writes_enabled, false);
    assert.ok(!r.text.includes(FAKE_SK) && !r.text.includes(FAKE_PK));
    s.stop();
  });

  test('un cargo exitoso con paid=false se traduce a paid; un rechazo a failed; una devolución parcial a review', async () => {
    const s = startServer();
    assert.equal((await s.tool('culqi_get_charge', { id: 'chr_test_PAIDPAIDPAID01' })).data.data.state_mova, 'paid');
    assert.equal((await s.tool('culqi_get_charge', { id: 'chr_test_DECLINEDDECL01' })).data.data.state_mova, 'failed');
    const list = await s.tool('culqi_list_charges', { limit: 3 });
    assert.deepEqual(list.data.data.map((c) => c.state_mova), ['paid', 'failed', 'review']);
    const report = await s.tool('culqi_reconcile_report');
    assert.deepEqual(report.data.by_state_mova, { paid: 1, failed: 1, review: 1 });
    s.stop();
  });

  test('los correos salen enmascarados y los eventos traen el id del recurso', async () => {
    const s = startServer();
    const c = (await s.tool('culqi_get_charge', { id: 'chr_test_PAIDPAIDPAID01' })).data.data;
    assert.equal(c.email, 'p***@example.com');
    const ev = (await s.tool('culqi_list_events')).data.data[0];
    assert.equal(ev.data_id, 'chr_test_PAIDPAIDPAID01');
    s.stop();
  });

  test('un error de la API que contiene la llave sale redactado', async () => {
    const s = startServer();
    const r = await s.tool('culqi_get_charge', { id: 'chr_test_BADBADBADBAD01' });
    assert.equal(r.isError, true);
    assert.ok(!r.text.includes(FAKE_SK));
    assert.ok(r.text.includes('[REDACTADO]'));
    s.stop();
  });

  test('ids mal formados se rechazan antes de llamar a Culqi', async () => {
    const s = startServer();
    const r = await s.tool('culqi_get_charge', { id: '../../v2/customers' });
    assert.equal(r.isError, true);
    s.stop();
  });
});

describe('escrituras de sandbox', () => {
  test('desactivadas por defecto: no se llama a Culqi', async () => {
    const before = { ...calls };
    const s = startServer();
    const r = await s.tool('culqi_sandbox_charge', { request_id: 'req-disabled-0001', scenario: 'approved_visa_debit', amount_minor: 500 });
    assert.equal(r.isError, true);
    assert.match(r.text, /desactivadas/);
    assert.deepEqual(calls, before);
    s.stop();
  });

  test('un request_id repetido devuelve el resultado guardado y NO reenvía el cobro', async () => {
    const s = startServer({ CULQI_MCP_ALLOW_WRITES: 'true' });
    const args = { request_id: 'req-idem-000001', scenario: 'approved_visa_debit', amount_minor: 500 };
    const c0 = { ...calls };
    const first = await s.tool('culqi_sandbox_charge', args);
    assert.equal(first.data.replayed, false);
    assert.equal(first.data.data.state_mova, 'paid');
    assert.equal(first.data.data.metadata.mcp_request_id, 'req-idem-000001');
    assert.equal(calls.charges - c0.charges, 1);

    const again = await s.tool('culqi_sandbox_charge', args);
    assert.equal(again.data.replayed, true);
    assert.equal(calls.charges - c0.charges, 1, 'no hubo un segundo POST /v2/charges');
    assert.equal(calls.tokens - c0.tokens, 1);

    const clash = await s.tool('culqi_sandbox_charge', { ...args, amount_minor: 600 });
    assert.equal(clash.isError, true);
    assert.match(clash.text, /parámetros distintos/);
    s.stop();
  });

  test('un rechazo se registra y su repetición tampoco reenvía', async () => {
    const s = startServer({ CULQI_MCP_ALLOW_WRITES: 'true' });
    const args = { request_id: 'req-decl-000001', scenario: 'declined_insufficient_funds', amount_minor: 500 };
    const c0 = calls.charges;
    const first = await s.tool('culqi_sandbox_charge', args);
    assert.equal(first.isError, true);
    assert.equal(first.data.error.charge_id, 'chr_test_DECLINEDDECL01');
    await s.tool('culqi_sandbox_charge', args);
    assert.equal(calls.charges - c0, 1);
    s.stop();
  });

  test('un corte de red a mitad del POST queda como incierto y no se reintenta ni se repite', async () => {
    const s = startServer({ CULQI_MCP_ALLOW_WRITES: 'true' });
    const args = { request_id: 'req-cut-0000001', scenario: 'approved_visa_debit', amount_minor: 777 };
    const c0 = calls.charges;
    const first = await s.tool('culqi_sandbox_charge', args);
    assert.equal(first.data.uncertain, true);
    assert.equal(calls.charges - c0, 1, 'el POST no se reintentó');
    await s.tool('culqi_sandbox_charge', args);
    assert.equal(calls.charges - c0, 1, 'tampoco al repetir el request_id');
    s.stop();
  });

  test('un request_id "iniciado" sin resultado (proceso caído) se rechaza en vez de reenviarse', async () => {
    const s = startServer({ CULQI_MCP_ALLOW_WRITES: 'true' });
    fs.mkdirSync(path.dirname(s.ledger), { recursive: true });
    const params = { scenario: 'approved_visa_debit', amount_minor: 500, email: 'sandbox-mcp@example.com' };
    const hash = (await import('node:crypto')).createHash('sha256').update(JSON.stringify(params)).digest('hex').slice(0, 16);
    fs.writeFileSync(s.ledger, JSON.stringify({ request_id: 'req-crash-00001', tool: 'culqi_sandbox_charge', params_hash: hash, status: 'started' }) + '\n');
    const c0 = calls.charges;
    const r = await s.tool('culqi_sandbox_charge', { request_id: 'req-crash-00001', scenario: 'approved_visa_debit', amount_minor: 500 });
    assert.equal(r.isError, true);
    assert.match(r.text, /iniciado/);
    assert.equal(calls.charges, c0);
    s.stop();
  });

  test('el importe está topado y request_id es obligatorio', async () => {
    const s = startServer({ CULQI_MCP_ALLOW_WRITES: 'true' });
    for (const amount_minor of [99, 1001, 100000]) {
      const r = await s.tool('culqi_sandbox_charge', { request_id: 'req-cap-0000001', scenario: 'approved_visa_debit', amount_minor });
      assert.equal(r.isError, true, String(amount_minor));
    }
    assert.equal((await s.tool('culqi_sandbox_charge', { scenario: 'approved_visa_debit', amount_minor: 500 })).isError, true);
    assert.equal((await s.tool('culqi_sandbox_charge', { request_id: 'req-nocard-00001', scenario: '4111111111111111', amount_minor: 500 })).isError, true, 'el modelo no puede pasar un PAN');
    s.stop();
  });

  test('devolución: solo cargos de prueba, una vez por request_id, sin exceder lo devolvible', async () => {
    const s = startServer({ CULQI_MCP_ALLOW_WRITES: 'true' });
    const bad = await s.tool('culqi_sandbox_refund', { request_id: 'req-ref-bad-0001', charge_id: 'chr_live_PAIDPAIDPAID01', amount_minor: 500, reason: 'solicitud_comprador' });
    assert.equal(bad.isError, true);

    const r0 = calls.refunds;
    const args = { request_id: 'req-ref-ok-00001', charge_id: 'chr_test_PAIDPAIDPAID01', amount_minor: 500, reason: 'solicitud_comprador' };
    assert.equal((await s.tool('culqi_sandbox_refund', args)).data.data.status, 'Completa');
    assert.equal((await s.tool('culqi_sandbox_refund', args)).data.replayed, true);
    assert.equal(calls.refunds - r0, 1);

    const over = await s.tool('culqi_sandbox_refund', { ...args, request_id: 'req-ref-over-001', amount_minor: 600 });
    assert.equal(over.isError, true, 'supera el tope de sandbox');
    s.stop();
  });
});

describe('hosts y concurrencia', () => {
  test('sin CULQI_MCP_TEST_MODE la llave secreta solo viaja a los hosts oficiales de Culqi', async () => {
    const c0 = { ...calls };
    const s = startServer({ CULQI_MCP_TEST_MODE: '0' });
    const st = await s.tool('culqi_status');
    assert.equal(st.data.ok, false);
    assert.match(st.data.problems.join(' '), /api.culqi.com/);
    assert.equal((await s.tool('culqi_list_charges')).isError, true);
    assert.deepEqual(calls, c0, 'no se llamó al host no oficial');
    s.stop();
  });

  test('dos procesos MCP con el mismo request_id: solo uno envía el cargo', async () => {
    const ledger = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'culqi-mcp-shared-')), 'ledger.jsonl');
    const a = startServer({ CULQI_MCP_ALLOW_WRITES: 'true', CULQI_MCP_LEDGER: ledger });
    const b = startServer({ CULQI_MCP_ALLOW_WRITES: 'true', CULQI_MCP_LEDGER: ledger });
    const args = { request_id: 'req-race-000001', scenario: 'approved_visa_debit', amount_minor: 500 };
    const c0 = calls.charges;
    const [ra, rb] = await Promise.all([a.tool('culqi_sandbox_charge', args), b.tool('culqi_sandbox_charge', args)]);
    assert.equal(calls.charges - c0, 1, 'un solo POST /v2/charges entre ambos procesos');
    const outcomes = [ra, rb].map((r) => (r.isError ? 'bloqueado' : r.data.replayed ? 'replay' : 'envio'));
    assert.equal(outcomes.filter((o) => o === 'envio').length, 1, outcomes.join());
    a.stop();
    b.stop();
  });
});

describe('llaves live', () => {
  const LIVE = { CULQI_ENV: 'live', CULQI_SECRET_KEY: 'sk_live_MockMockMock1234', CULQI_PUBLIC_KEY: 'pk_live_MockMockMock1234' };

  test('las escrituras live están bloqueadas aunque se active el flag; las lecturas live exigen un flag explícito', async () => {
    const s = startServer({ ...LIVE, CULQI_MCP_ALLOW_WRITES: 'true' });
    const c0 = { ...calls };
    const w = await s.tool('culqi_sandbox_charge', { request_id: 'req-live-000001', scenario: 'approved_visa_debit', amount_minor: 500 });
    assert.equal(w.isError, true);
    assert.match(w.text, /NUNCA|bloqueadas/);
    const r = await s.tool('culqi_get_charge', { id: 'chr_test_PAIDPAIDPAID01' });
    assert.equal(r.isError, true);
    assert.match(r.text, /live/i);
    assert.deepEqual(calls, c0);
    s.stop();
  });

  test('modos mezclados o llaves de ejemplo invalidan la configuración', async () => {
    const mixed = startServer({ CULQI_PUBLIC_KEY: 'pk_live_MockMockMock1234' });
    assert.equal((await mixed.tool('culqi_status')).data.ok, false);
    mixed.stop();
    const placeholder = startServer({ CULQI_SECRET_KEY: 'tu_llave_privada' });
    const st = await placeholder.tool('culqi_status');
    assert.equal(st.data.ok, false);
    assert.equal((await placeholder.tool('culqi_list_charges')).isError, true);
    placeholder.stop();
  });
});

describe('traducción de estados (misma regla que CulqiChargeMapper de PHP)', async () => {
  const { normalizeCharge } = await import('./server.mjs');
  const base = success();

  test('casos canónicos', () => {
    assert.equal(normalizeCharge(base), 'paid');
    assert.equal(normalizeCharge(declined), 'failed');
    assert.equal(normalizeCharge({ ...base, amount_refunded: 500 }), 'reversed');
    assert.equal(normalizeCharge({ ...base, amount_refunded: 100 }), 'review');
    assert.equal(normalizeCharge({ ...base, dispute: true }), 'review');
    assert.equal(normalizeCharge({ ...base, capture: false }), 'review');
    assert.equal(normalizeCharge({ ...base, outcome: { type: 'otro', code: 'X' } }), 'review');
    assert.equal(normalizeCharge({ ...base, outcome: null }), 'review');
    assert.equal(normalizeCharge({ ...base, outcome: { code: 'succesfull_charge' }, paid: true }), 'paid');
    assert.equal(normalizeCharge({ ...base, amount: 0 }), 'review');
  });
});
