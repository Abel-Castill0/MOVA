// Autotest de los hooks: `node .claude/hooks/selftest.mjs` (≈2 s, sin red, sin tocar el repo).
// Cada hook se prueba con un caso válido (debe pasar en silencio) y uno que debe
// advertir/rechazar. Los "secretos" se fabrican en tiempo de ejecución con forma válida
// pero sin valor real, y los repos git/archivos de prueba viven en un directorio temporal.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const repo = path.resolve(here, '..', '..');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'mova-hooks-'));
let failed = 0;
let total = 0;

function run(script, input, { cwd = repo, args = [], env = {} } = {}) {
  const r = spawnSync(process.execPath, [path.join(here, script), ...args], {
    input: JSON.stringify(input),
    cwd,
    encoding: 'utf8',
    env: { ...process.env, CLAUDE_PROJECT_DIR: cwd, ...env },
  });
  let out = null;
  try { out = r.stdout.trim() ? JSON.parse(r.stdout) : null; } catch { out = { raw: r.stdout }; }
  return { status: r.status, out, raw: r.stdout, err: r.stderr };
}
const decision = (r) => r.out?.hookSpecificOutput?.permissionDecision ?? 'allow';
function check(name, cond, detail = '') {
  total++;
  if (!cond) failed++;
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${name}${cond ? '' : '  -> ' + detail}`);
}
const bash = (command, opts) => run('guard-bash.mjs', { tool_name: 'Bash', tool_input: { command } }, opts);

// --- guard-bash: casos válidos (deben pasar en silencio)
for (const c of ['ls -la', 'git status -sb', 'git log --oneline -5', 'php artisan test --filter=PaymentOrderTest', 'npm run build', 'cat .env.example', 'git commit -m "docs: nota sobre git reset --hard y cat .env"']) {
  const r = bash(c);
  check(`bash allow: ${c.slice(0, 55)}`, decision(r) === 'allow' && r.status === 0, JSON.stringify(r.out));
}
// --- guard-bash: casos que deben pedir confirmación
for (const c of ['git reset --hard HEAD~1', 'git push --force origin master', 'git push origin +master', 'git clean -fd', 'git checkout -- .', 'git branch -D feature/x', 'rm -rf storage/logs', 'php artisan migrate:fresh --seed', 'php artisan db:seed', 'cat .env', 'grep APP_KEY .env.production', 'Get-Content .env']) {
  const r = bash(c);
  check(`bash ask: ${c}`, decision(r) === 'ask', JSON.stringify(r.out));
}

// --- guard-bash: .env citado como TEXTO o consultas que no revelan valores → no preguntan
for (const c of [
  `printf 'ejecuta: grep -c APP_KEY .env.production' > prompt.txt`,
  `echo "no hagas cat .env" && ls`,
  'grep -c APP_KEY .env.production',
  'grep -q APP_KEY .env',
  'grep -l APP_KEY .env.production .env',
  'ls -la .env',
  'cat .env.example',
  'git commit -m "docs: no imprimir cat .env.production"',
]) {
  const r = bash(c);
  check(`bash allow (.env solo texto/quieto): ${c.slice(0, 60)}`, decision(r) === 'allow' && r.out === null, JSON.stringify(r.out));
}
// --- .env como argumento real de un comando que imprime → sigue preguntando (también tras && o con comillas)
for (const c of ['cat ".env"', 'echo ok && cat .env.production', 'head -5 ./.env', 'grep APP_KEY .env.production', 'tail qa/.env.qa | wc -l']) {
  check(`bash ask (.env impreso): ${c}`, decision(bash(c)) === 'ask');
}
// --- modo "omitir permisos": el .env no fuerza el prompt (solo aviso); las operaciones destructivas siguen pidiendo
const byp = (command) => run('guard-bash.mjs', { tool_name: 'Bash', permission_mode: 'bypassPermissions', tool_input: { command } });
const rb = byp('cat .env');
check('bypassPermissions: cat .env sin prompt, con aviso', decision(rb) === 'allow' && /omitir-permisos/.test(rb.out?.hookSpecificOutput?.additionalContext ?? ''), JSON.stringify(rb.out));
check('bypassPermissions: git reset --hard sigue pidiendo confirmación', decision(byp('git reset --hard HEAD~1')) === 'ask');

// --- guard-bash: git commit con/ sin secretos en un repo temporal
const sandbox = path.join(tmp, 'repo');
fs.mkdirSync(sandbox);
const g = (...a) => spawnSync('git', a, { cwd: sandbox, encoding: 'utf8' });
g('init', '-q'); g('config', 'user.email', 't@t'); g('config', 'user.name', 't');
fs.writeFileSync(path.join(sandbox, 'ok.php'), '<?php echo 1;\n');
g('add', 'ok.php');
check('commit limpio permitido', decision(bash('git commit -m "ok"', { cwd: sandbox })) === 'allow');
const fake = ['APP_USR', '1234567890123456', '123456', 'a'.repeat(32), '7654321'].join('-');
fs.writeFileSync(path.join(sandbox, 'cfg.php'), `<?php return ['t' => '${fake}'];\n`);
g('add', 'cfg.php');
const rs = bash('git commit -m "x"', { cwd: sandbox });
check('commit con token MP denegado', decision(rs) === 'deny', JSON.stringify(rs.out));
check('el motivo no revela el valor', !JSON.stringify(rs.out).includes(fake));
g('reset', '-q', 'cfg.php');
fs.writeFileSync(path.join(sandbox, '.env'), 'APP_KEY=x\n');
g('add', '-f', '.env');
check('commit con .env denegado', decision(bash('git commit -m "x"', { cwd: sandbox })) === 'deny');

// --- guard-files
const files = (tool_input, tool_name = 'Write') => run('guard-files.mjs', { tool_name, tool_input });
check('files allow: app/Services/Foo.php', decision(files({ file_path: path.join(repo, 'app/Services/Foo.php'), content: '<?php // ok' })) === 'allow');
check('files allow: migración nueva', decision(files({ file_path: path.join(repo, 'database/migrations/2099_01_01_000001_nueva.php'), content: '<?php' })) === 'allow');
check('files allow: .env.example', decision(files({ file_path: path.join(repo, '.env.example'), content: 'APP_KEY=' })) === 'allow');
check('files ask: .env', decision(files({ file_path: path.join(repo, '.env'), content: 'A=1' })) === 'ask');
check('files ask: qa/auth/admin.json', decision(files({ file_path: path.join(repo, 'qa/auth/admin.json'), content: '{}' })) === 'ask');
const existing = fs.readdirSync(path.join(repo, 'database/migrations')).find((f) => f.endsWith('.php'));
check('files ask: migración existente', decision(files({ file_path: path.join(repo, 'database/migrations', existing), old_string: 'a', new_string: 'b' }, 'Edit')) === 'ask');
const rd = files({ file_path: path.join(repo, 'config/services.php'), content: `'k' => '${fake}'` });
check('files deny: secreto en archivo versionable', decision(rd) === 'deny' && !JSON.stringify(rd.out).includes(fake));

// --- post-edit
const bad = path.join(tmp, 'bad.php'); fs.writeFileSync(bad, '<?php\nfunction (( {\n');
const good = path.join(tmp, 'good.php'); fs.writeFileSync(good, '<?php\necho 1;\n');
const pe = (file_path, session_id = 's1') => run('post-edit.mjs', { tool_name: 'Edit', tool_input: { file_path }, session_id }, { cwd: tmp });
check('post-edit: PHP válido en silencio', pe(good).out === null, JSON.stringify(pe(good).out));
const phpAvail = spawnSync('php', ['-v']).status === 0;
if (phpAvail) check('post-edit: PHP roto avisa', /php -l/.test(JSON.stringify(pe(bad).out ?? '')));
else console.log('SKIP  post-edit PHP roto (php no disponible)');
const sess = `t${Date.now()}`;
const money = path.join(repo, 'app/Services/RechargeApprovalService.php');
const first = run('post-edit.mjs', { tool_name: 'Edit', tool_input: { file_path: money }, session_id: sess });
const second = run('post-edit.mjs', { tool_name: 'Edit', tool_input: { file_path: money }, session_id: sess });
check('post-edit: zona dinero avisa una vez', /mova-security-finance/.test(JSON.stringify(first.out ?? '')) && second.out === null, JSON.stringify([first.out, second.out]));
fs.rmSync(path.join(os.tmpdir(), `mova-hook-${sess}.json`), { force: true });

// --- snapshot
const proj = path.join(tmp, 'proj'); fs.mkdirSync(proj);
spawnSync('git', ['init', '-q'], { cwd: proj }); spawnSync('git', ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '--allow-empty', '-m', 'i'], { cwd: proj });
run('snapshot.mjs', { hook_event_name: 'PreCompact' }, { cwd: proj, args: ['save'] });
check('snapshot save escribe .claude/state', fs.existsSync(path.join(proj, '.claude/state/session-snapshot.json')));
fs.writeFileSync(path.join(proj, 'nuevo.txt'), 'x');
const st = run('snapshot.mjs', { hook_event_name: 'SessionStart' }, { cwd: proj, args: ['start'] });
check('snapshot start imprime contexto breve', /MOVA ·/.test(st.raw) && st.raw.split('\n').length <= 5, st.raw);

// --- prompt-hint (UserPromptSubmit): clasifica sin alterar ni bloquear
const hint = (prompt) => run('optional/prompt-hint.mjs', { hook_event_name: 'UserPromptSubmit', prompt });
const ctx = (r) => r.out?.hookSpecificOutput?.additionalContext ?? null;
const cases = [
  ['arregla los créditos cuando cancelan una clase', /Dinero/],
  ['revisa por qué está fallando Mercado Pago', /Dinero/],
  ['el dashboard del profesor está muy feo, mejóralo', /Frontend/],
  ['haz que el administrador pueda controlar las solicitudes', /Funcionalidad/],
  ['haz commit de lo que cambié', /Git/],
  ['cómo es la seguridad del login de los padres', /Seguridad/],
  ['por qué se cae la cola de correos en producción', /Depuración|Backend/],
];
for (const [p, re] of cases) {
  const r = hint(p);
  check(`hint "${p.slice(0, 40)}"`, r.status === 0 && re.test(ctx(r) ?? ''), JSON.stringify(r.out));
  check('  contexto ≤ 50 palabras y sin decisión de bloqueo', (ctx(r) ?? '').split(/\s+/).length <= 50 && !r.out?.decision && !r.out?.hookSpecificOutput?.permissionDecision);
}
for (const p of ['ok', 'gracias, sigue', '/mova-audit app', '¿qué hace credit() en RechargeApprovalService?', 'en StatusBadge.vue ponle role="status"', 'cuál es la capital de Perú',
  '<task-notification>subagent terminó: arregla el error de pago</task-notification>', 'x'.repeat(450) + ' pago error']) {
  check(`hint silencio: ${p.slice(0, 40)}`, hint(p).out === null);
}
check('hint tolera entrada vacía/corrupta', run('optional/prompt-hint.mjs', {}).status === 0);

// --- agentes: todos de solo lectura y con frontmatter coherente
const agentsDir = path.join(repo, '.claude/agents');
for (const f of fs.readdirSync(agentsDir).filter((x) => x.startsWith('mova-'))) {
  const fm = /^---\n([\s\S]*?)\n---/.exec(fs.readFileSync(path.join(agentsDir, f), 'utf8'))?.[1] ?? '';
  const tools = /^tools:\s*(.+)$/m.exec(fm)?.[1].split(',').map((s) => s.trim()) ?? [];
  const nameOk = new RegExp(`^name:\\s*${f.replace('.md', '')}\\s*$`, 'm').test(fm);
  check(`agente ${f}: nombre=archivo, solo Read/Grep/Glob`, nameOk && tools.length > 0 && tools.every((t) => ['Read', 'Grep', 'Glob'].includes(t)), `tools=${tools}`);
}

fs.rmSync(tmp, { recursive: true, force: true });
console.log(`\n${total - failed}/${total} OK`);
process.exit(failed ? 1 : 0);
