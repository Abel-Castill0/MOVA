// PreToolUse(Bash): freno de seguridad, nunca ejecuta nada.
//  - DENY  : `git commit` con secretos reconocibles o archivos sensibles en el commit.
//  - ASK   : operaciones destructivas (git reset --hard, force push, clean, rm -rf,
//            migrate:fresh/reset/rollback, db:wipe/seed, DROP/TRUNCATE) y comandos que
//            imprimirían el contenido de un .env real.
// Todo lo demás pasa sin salida (no añade latencia perceptible ni ruido).
import { readInput, projectDir, git, ask, deny, context, scanText, isSensitiveFile } from './lib.mjs';

const input = readInput();
if (input.tool_name && input.tool_name !== 'Bash') process.exit(0);
const raw = String(input.tool_input?.command ?? '');
if (!raw) process.exit(0);

// Quita heredocs y textos entre comillas para no disparar con mensajes de commit
// o argumentos que solo mencionan el comando peligroso.
const cmd = raw
  .replace(/<<-?\s*['"]?(\w+)['"]?[\s\S]*?\n\s*\1\b/g, ' ')
  .replace(/"(?:[^"\\]|\\.)*"|'[^']*'/g, '""');

const cwd = input.cwd || projectDir(input);

// 1) git commit: escanea lo que va a entrar al commit.
if (/\bgit\s+(?:-C\s+\S+\s+)?commit\b/.test(cmd)) {
  const all = /\s(?:-[a-zA-Z]*a[a-zA-Z]*|--all)(?:\s|$)/.test(cmd);
  const names = git(all ? ['diff', 'HEAD', '--name-only'] : ['diff', '--cached', '--name-only'], cwd)
    .split('\n').map((s) => s.trim()).filter(Boolean);
  const sensitive = names.filter(isSensitiveFile);
  const diff = git(all ? ['diff', 'HEAD', '-U0'] : ['diff', '--cached', '-U0'], cwd);
  const added = diff.split('\n').filter((l) => l.startsWith('+') && !l.startsWith('+++')).join('\n');
  const hits = scanText(added);
  const hard = hits.filter((h) => h.severity === 'hard');
  if (sensitive.length || hard.length) {
    const parts = [];
    if (sensitive.length) parts.push(`archivos sensibles en el commit: ${sensitive.slice(0, 5).join(', ')}`);
    if (hard.length) parts.push(`posibles secretos en líneas añadidas: ${[...new Set(hard.map((h) => h.name))].join(', ')}`);
    deny(`Commit bloqueado (${parts.join('; ')}). Quítalos del índice (git restore --staged <archivo>) o rota el secreto; los valores no se muestran.`);
    process.exit(0);
  }
  if (hits.length) {
    ask(`El commit añade algo con forma de ${[...new Set(hits.map((h) => h.name))].join(', ')}. Confirma que es un fixture/valor no real.`);
    process.exit(0);
  }
}

// 2) Operaciones destructivas o difíciles de revertir.
const risky = [
  [/\bgit\s+(?:-C\s+\S+\s+)?reset\b[^|;&\n]*--hard/, 'git reset --hard descarta cambios locales (AGENTS.md: solo con orden explícita)'],
  [/\bgit\s+(?:-C\s+\S+\s+)?push\b[^|;&\n]*(?:--force\b|--force-with-lease|\s-f\b|\s\+\S)/, 'force push reescribe historia remota'],
  [/\bgit\s+(?:-C\s+\S+\s+)?clean\b[^|;&\n]*\s-[a-zA-Z]*f/, 'git clean -f borra archivos sin versionar'],
  [/\bgit\s+(?:-C\s+\S+\s+)?(?:checkout\s+(?:--\s+)?\.|restore\s+(?:--\S+\s+)*\.)(?:\s|$)/, 'descarta todos los cambios del working tree'],
  [/\bgit\s+(?:-C\s+\S+\s+)?branch\b[^|;&\n]*\s-D\b/, 'git branch -D borra una rama sin comprobar merge'],
  [/\brm\s+(?:-[a-zA-Z]*r[a-zA-Z]*f|-[a-zA-Z]*f[a-zA-Z]*r|--recursive\s+--force|-r\s+-f|-f\s+-r)\b/, 'rm -rf es irreversible'],
  [/\bRemove-Item\b[^|;&\n]*-Recurse[^|;&\n]*-Force|\bRemove-Item\b[^|;&\n]*-Force[^|;&\n]*-Recurse/i, 'Remove-Item -Recurse -Force es irreversible'],
  [/\bartisan\s+(?:migrate:fresh|migrate:reset|migrate:rollback|db:wipe)\b/, 'operación destructiva de esquema; solo sobre SQLite de pruebas o mova_qa'],
  [/\bartisan\s+db:seed\b/, 'AGENTS.md: no ejecutes seeders en entornos reales'],
  [/\b(?:DROP\s+(?:TABLE|DATABASE|SCHEMA)|TRUNCATE\s+TABLE)\b/i, 'SQL destructivo'],
];
for (const [re, why] of risky) {
  if (re.test(cmd)) {
    ask(`Operación de riesgo: ${why}. Verifica entorno/objetivo antes de continuar.`);
    process.exit(0);
  }
}

// 3) Imprimir un .env real (no *.example). Se analiza comando a comando y el .env solo cuenta
// como ARGUMENTO de un comando que imprime contenido: el texto entre comillas (printf, echo,
// mensajes de commit, prompts) y las consultas que no revelan valores (grep -c/-q/-l) no activan nada.
const PRINTERS = new Set(['cat', 'type', 'more', 'less', 'head', 'tail', 'sed', 'awk', 'grep', 'egrep', 'fgrep', 'rg', 'bat', 'nl', 'tac', 'od', 'xxd', 'strings',
  'get-content', 'gc', 'select-string', 'printenv', 'env']);
const isEnvArg = (t) => /(?:^|[\\/])\.env(?:\.[\w.-]+)?$/.test(t) && !/\.example$/i.test(t);
const unquoted = raw
  .replace(/<<-?\s*['"]?(\w+)['"]?[\s\S]*?\n\s*\1\b/g, ' ')
  .replace(/"((?:[^"\\]|\\.)*)"|'([^']*)'/g, (m, a, b) => { const s = a ?? b ?? ''; return /^\S+$/.test(s) && isEnvArg(s) ? ` ${s} ` : '""'; });
const quietFlag = (a) => /^-[A-Za-z]*[cqlL][A-Za-z]*$/.test(a) || /^--(?:count|quiet|silent|files-with-matches|files-without-match)$/.test(a);
const readsEnv = unquoted.split(/&&|\|\||[;|\n]/).some((seg) => {
  const tokens = seg.trim().split(/\s+/).filter(Boolean);
  while (tokens.length && (/^[A-Za-z_]\w*=/.test(tokens[0]) || ['sudo', 'command', 'builtin', 'time'].includes(tokens[0].toLowerCase()))) tokens.shift();
  const name = (tokens[0] ?? '').split(/[\\/]/).pop().replace(/\.exe$/i, '').toLowerCase();
  if (!PRINTERS.has(name)) return false;
  const args = tokens.slice(1);
  if (!args.some(isEnvArg)) return false;
  if (['grep', 'egrep', 'fgrep', 'rg'].includes(name) && args.some(quietFlag)) return false; // solo conteo/existencia
  return true;
});
if (readsEnv) {
  if (input.permission_mode === 'bypassPermissions') {
    // El usuario eligió "omitir permisos": no se fuerza el prompt; queda solo el aviso al modelo.
    context('PreToolUse', 'Lectura de un .env real en modo omitir-permisos: no muestres valores de secretos (AGENTS.md); valida solo presencia/formato.');
  } else {
    ask('El comando leería un .env real y podría imprimir secretos (AGENTS.md: no mostrar valores). Valida solo presencia/formato (p. ej. grep -c), o usa un script que no los imprima.');
  }
}
