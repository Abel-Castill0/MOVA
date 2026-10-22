// Utilidades compartidas de los hooks de MOVA (Node >= 18, sin dependencias).
// Contrato: leer JSON de stdin; responder con JSON en stdout (exit 0) o no
// imprimir nada para permitir. Nunca hacen push/deploy ni llamadas externas, y
// nunca imprimen el valor de un secreto: solo el tipo de patrón y el archivo.
import fs from 'node:fs';
import { spawnSync } from 'node:child_process';
import path from 'node:path';

export function readInput() {
  try {
    const raw = fs.readFileSync(0, 'utf8');
    return raw.trim() ? JSON.parse(raw) : {};
  } catch {
    return {};
  }
}

export function projectDir(input = {}) {
  return process.env.CLAUDE_PROJECT_DIR || input.cwd || process.cwd();
}

export function git(args, cwd) {
  const r = spawnSync('git', args, { cwd, encoding: 'utf8', timeout: 8000, windowsHide: true, maxBuffer: 8 * 1024 * 1024 });
  return r.status === 0 ? r.stdout : '';
}

function emit(obj) {
  process.stdout.write(JSON.stringify(obj));
}

export function decide(decision, reason) {
  emit({
    hookSpecificOutput: {
      hookEventName: 'PreToolUse',
      permissionDecision: decision,
      permissionDecisionReason: `[MOVA hook] ${reason}`,
    },
  });
}
export const ask = (reason) => decide('ask', reason);
export const deny = (reason) => decide('deny', reason);

export function context(event, text) {
  emit({ hookSpecificOutput: { hookEventName: event, additionalContext: `[MOVA hook] ${text}` } });
}

/** Ruta normalizada con "/" y relativa al proyecto cuando es posible. */
export function relPath(file, root) {
  if (!file) return '';
  const norm = (p) => path.resolve(p).replace(/\\/g, '/');
  const f = norm(file);
  const r = norm(root).replace(/\/$/, '');
  return f.toLowerCase().startsWith(r.toLowerCase() + '/') ? f.slice(r.length + 1) : f;
}

// --- Detección de secretos: patrones de alta precisión (evitan falsos positivos). ---
// severity 'hard' => deny; 'soft' => ask. Los patrones se construyen sin literales
// completos para que este archivo no dispare su propio escáner.
const p = (...parts) => new RegExp(parts.join(''));
export const SECRET_PATTERNS = [
  { name: 'Mercado Pago access token', severity: 'hard', re: p('(?:APP_USR|TEST)-\\d{10,}-\\d{6}-[a-f0-9]{32}', '-\\d+') },
  { name: 'clave privada PEM', severity: 'hard', re: p('-----BEGIN (?:RSA |EC |OPENSSH |DSA |ENCRYPTED )?PRIVATE', ' KEY-----') },
  { name: 'AWS access key', severity: 'hard', re: p('\\bAKI', 'A[0-9A-Z]{16}\\b') },
  { name: 'GitHub token', severity: 'hard', re: p('\\bgh[pousr]', '_[A-Za-z0-9]{36,}\\b') },
  { name: 'GitHub fine-grained token', severity: 'hard', re: p('\\bgithub_', 'pat_[A-Za-z0-9_]{50,}') },
  { name: 'Google API key', severity: 'hard', re: p('\\bAI', 'za[0-9A-Za-z_-]{35}\\b') },
  { name: 'Google OAuth refresh token', severity: 'hard', re: p('\\b1/', '/[0-9A-Za-z_-]{45,}') },
  { name: 'Slack token', severity: 'hard', re: p('\\bxox[baprs]', '-[0-9A-Za-z-]{10,}') },
  { name: 'Stripe/live secret', severity: 'hard', re: p('\\bsk_li', 've_[0-9a-zA-Z]{20,}') },
  { name: 'Sentry DSN con clave', severity: 'hard', re: p('https://[a-f0-9]{32}@[\\w.-]*sent', 'ry\\.io/\\d+') },
  { name: 'Cloudinary URL con secreto', severity: 'hard', re: p('cloudinary', '://\\d+:[\\w-]+@') },
  { name: 'JWT (revisar que no sea real)', severity: 'soft', re: p('\\bey', 'J[A-Za-z0-9_-]{15,}\\.ey', 'J[A-Za-z0-9_-]{15,}\\.[A-Za-z0-9_-]{10,}') },
];

/** Devuelve [{name, severity}] sin incluir nunca el valor coincidente. */
export function scanText(text) {
  const found = [];
  for (const s of SECRET_PATTERNS) if (s.re.test(text)) found.push({ name: s.name, severity: s.severity });
  return found;
}

/** Archivos que nunca deben versionarse (plantillas *.example permitidas). */
export function isSensitiveFile(rel) {
  const f = rel.replace(/\\/g, '/');
  if (/\.example$/i.test(f)) return false;
  const base = f.split('/').pop();
  return (
    /^\.env($|\.)/.test(base) ||
    /(^|\/)qa\/auth\//.test(f) ||
    /\.local\.env$/.test(base) ||
    base === 'auth.json' ||
    /\.(pem|p12|pfx)$/i.test(base) ||
    /^id_(rsa|ed25519|ecdsa)$/.test(base)
  );
}
