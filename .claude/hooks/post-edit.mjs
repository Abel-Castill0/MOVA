// PostToolUse(Edit|Write|MultiEdit): validación rápida del archivo editado (nunca la suite)
// y recordatorio, una vez por sesión y categoría, de revisar zonas sensibles.
// Solo informa (additionalContext); no bloquea ni modifica archivos.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { readInput, projectDir, context, relPath } from './lib.mjs';

const input = readInput();
const file = input.tool_input?.file_path ?? '';
if (!file) process.exit(0);
const root = projectDir(input);
const abs = path.isAbsolute(file) ? file : path.join(input.cwd || root, file);
const rel = relPath(abs, root);
if (!fs.existsSync(abs)) process.exit(0);

const notes = [];

// 1) Sintaxis rápida.
if (/\.php$/.test(abs)) {
  const r = spawnSync('php', ['-l', abs], { encoding: 'utf8', timeout: 10000, windowsHide: true });
  if (r.error) {
    // php no disponible: se omite sin ruido.
  } else if (r.status !== 0 && /Parse error|Fatal error/.test(r.stdout + r.stderr)) {
    const line = (r.stdout + r.stderr).split('\n').find((l) => /Parse error|Fatal error/.test(l)) ?? 'error de sintaxis';
    const v = spawnSync('php', ['-r', 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;'], { encoding: 'utf8', timeout: 5000, windowsHide: true });
    const old = parseFloat(v.stdout) < 8.3 ? ' (el PHP local es <8.3: puede ser falso positivo con sintaxis 8.3)' : '';
    notes.push(`php -l ${rel}: ${line.trim().slice(0, 200)}${old}`);
  }
} else if (/\.(mjs|cjs|js)$/.test(abs) && !/node_modules|public\/build/.test(rel) && !/\.vue$/.test(abs)) {
  // Los .js de resources/js son módulos ESM: --check de .js usa el tipo del package.json ("module").
  const r = spawnSync(process.execPath, ['--check', abs], { encoding: 'utf8', timeout: 10000, windowsHide: true });
  if (r.status !== 0) notes.push(`node --check ${rel}: ${(r.stderr || '').split('\n').find((l) => /Error/.test(l))?.slice(0, 200) ?? 'error de sintaxis'}`);
} else if (/\.json$/.test(abs)) {
  try {
    JSON.parse(fs.readFileSync(abs, 'utf8'));
  } catch (e) {
    notes.push(`JSON inválido en ${rel}: ${String(e.message).slice(0, 120)}`);
  }
}

// 2) Zonas sensibles: recordatorio único por sesión y categoría.
const zones = [
  ['dinero', /app\/(Services\/(Recharge|Payment|LessonSettlement|MercadoPago)|Payment\/|Models\/(CreditTransaction|PaymentOrder|PaymentWebhook|RechargeRequest)|Jobs\/ProcessMercadoPago|Http\/Controllers\/(MercadoPago|Teacher\/Credit|Admin\/Recharge))/, 'mova-security-finance'],
  ['auth/autorización', /app\/(Policies\/|Http\/Middleware\/(Ensure|Authenticate)|Services\/AdminMfaService|Http\/Controllers\/(Auth|Admin\/Mfa))|routes\/web\.php$/, 'mova-security-finance'],
  ['menores/privacidad/video', /app\/(Models\/(Student|TeacherProfile|StudentDataConsent|LegalAcceptance)|Services\/JaasService|Http\/Controllers\/(Student|JaasWebhook|LegalAcceptance))/, 'mova-security-finance'],
  ['migración', /^database\/migrations\//, 'mova-laravel-reviewer (y perfil MySQL en mova-safe-qa)'],
];
const zone = zones.find(([, re]) => re.test(rel));
if (zone) {
  const stateFile = path.join(os.tmpdir(), `mova-hook-${String(input.session_id ?? 'nosession').replace(/[^\w-]/g, '')}.json`);
  let state = {};
  try { state = JSON.parse(fs.readFileSync(stateFile, 'utf8')); } catch { /* primera vez */ }
  if (!state[zone[0]]) {
    state[zone[0]] = true;
    try { fs.writeFileSync(stateFile, JSON.stringify(state)); } catch { /* sin estado: se repetirá, no es grave */ }
    notes.push(`Zona sensible (${zone[0]}): ${rel}. Antes de cerrar la tarea, revisión con ${zone[2]}.`);
  }
}

if (notes.length) context('PostToolUse', notes.join(' | '));
