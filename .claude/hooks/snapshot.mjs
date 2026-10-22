// Preservación de contexto entre sesiones, sin tocar el árbol versionado.
//   node snapshot.mjs save   (PreCompact, SessionEnd): guarda .claude/state/session-snapshot.json
//   node snapshot.mjs start  (SessionStart): imprime ≤ 4 líneas de contexto (stdout → Claude)
// El snapshot contiene solo rama, HEAD, conteos y NOMBRES de archivos modificados (nunca contenido).
import fs from 'node:fs';
import path from 'node:path';
import { readInput, projectDir, git } from './lib.mjs';

const mode = process.argv[2];
const input = readInput();
const root = projectDir(input);
const stateDir = path.join(root, '.claude', 'state');
const file = path.join(stateDir, 'session-snapshot.json');

function current() {
  const branch = git(['rev-parse', '--abbrev-ref', 'HEAD'], root).trim();
  if (!branch) return null;
  const head = git(['rev-parse', '--short', 'HEAD'], root).trim();
  const dirty = git(['status', '--porcelain'], root).split('\n').map((l) => l.slice(3).trim()).filter(Boolean);
  const lr = git(['rev-list', '--left-right', '--count', `origin/${branch}...HEAD`], root).trim().split(/\s+/);
  return { branch, head, dirtyCount: dirty.length, dirty: dirty.slice(0, 40), behind: Number(lr[0] ?? 0) || 0, ahead: Number(lr[1] ?? 0) || 0 };
}

if (mode === 'save') {
  const snap = current();
  if (!snap) process.exit(0);
  try {
    fs.mkdirSync(stateDir, { recursive: true });
    fs.writeFileSync(file, JSON.stringify({ savedAt: new Date().toISOString(), event: input.hook_event_name ?? 'manual', ...snap }, null, 2));
  } catch { /* no crítico */ }
} else if (mode === 'start') {
  const now = current();
  if (!now) process.exit(0);
  const lines = [`MOVA · ${now.branch}@${now.head} · ${now.dirtyCount} archivo(s) sin commit · ↑${now.ahead} ↓${now.behind} vs origin`];
  try {
    const prev = JSON.parse(fs.readFileSync(file, 'utf8'));
    if (prev.head !== now.head || prev.dirtyCount !== now.dirtyCount) {
      lines.push(`Sesión anterior (${prev.savedAt?.slice(0, 16).replace('T', ' ')}): ${prev.branch}@${prev.head}, ${prev.dirtyCount} sin commit — el estado cambió desde entonces.`);
    }
  } catch { /* sin snapshot previo */ }
  if (fs.existsSync(path.join(root, 'docs', 'SESSION_HANDOFF.md'))) lines.push('Existe docs/SESSION_HANDOFF.md (léelo solo si continúas ese trabajo).');
  process.stdout.write(lines.join('\n') + '\n');
}
