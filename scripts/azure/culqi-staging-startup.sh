#!/bin/sh
# Arranque del staging aislado de Culqi (Container App `movaqa-culqi`; ver docs/culqi-evaluator-handoff.md).
# Se pasa como `command: [sh] args: [-c, <este archivo>]`. No contiene secretos: la contraseña llega como hash
# (EVALUATOR_PASSWORD_HASH), el secreto del webhook como CULQI_WEBHOOK_SECRET y las llaves como secretRef.
set -e
cd /app
# Persistencia: SQLite vive en disco local (rapido y con bloqueos fiables; SMB no soporta los bloqueos de SQLite).
# Cada 30 s y al apagar se guarda una copia consistente (VACUUM INTO local + integrity_check + copia simple) en
# el recurso compartido /mnt/qa; al arrancar se restaura.
mkdir -p /mnt/qa
if [ -s /mnt/qa/qa.sqlite ]; then cp /mnt/qa/qa.sqlite /app/storage/qa.sqlite; echo "[persist] restaurada copia principal"
elif [ -s /mnt/qa/qa.sqlite.prev ]; then cp /mnt/qa/qa.sqlite.prev /app/storage/qa.sqlite; echo "[persist] restaurada copia previa"
else touch /app/storage/qa.sqlite; echo "[persist] base nueva"; fi
cat > /tmp/bk.php <<'PHP'
<?php
$src = '/app/storage/qa.sqlite'; $loc = '/tmp/qa.bak.sqlite'; $tmp = '/mnt/qa/qa.sqlite.tmp'; $dst = '/mnt/qa/qa.sqlite';
@unlink($loc);
$pdo = new PDO('sqlite:'.$src); $pdo->exec('PRAGMA busy_timeout=20000');
$pdo->exec("VACUUM INTO '".$loc."'");
$chk = new PDO('sqlite:'.$loc);
if ($chk->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') { fwrite(STDERR, "[persist] integrity_check fallo; no se sobrescribe\n"); exit(1); }
$chk = null;
if (!copy($loc, $tmp) || filesize($tmp) !== filesize($loc)) { fwrite(STDERR, "[persist] copia incompleta\n"); @unlink($tmp); exit(1); }
if (is_file($dst) && filesize($dst) > 0) { @copy($dst, $dst.'.prev'); }
rename($tmp, $dst);
echo '[persist] copia guardada '.filesize($dst)." bytes\n";
PHP
php artisan migrate --force
php artisan db:seed --class=RoleSeeder --force
cat > /tmp/prov.php <<'PHP'
<?php
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$e = getenv('EVALUATOR_EMAIL'); $h = getenv('EVALUATOR_PASSWORD_HASH');
Illuminate\Support\Facades\Artisan::call('mova:culqi-evaluator', ['action' => 'create', 'email' => $e]);
Illuminate\Support\Facades\DB::table('users')->where('email', $e)->update(['password' => $h]);
echo "evaluator ready\n";
PHP
php /tmp/prov.php
cat > /tmp/chk.php <<'PHP'
<?php
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$g = fn($t, $c) => DB::table($t)->select($c, DB::raw('count(*) n'))->groupBy($c)->pluck('n', $c)->all();
$u = DB::table('users')->where('email', getenv('EVALUATOR_EMAIL'))->first();
$p = $u ? DB::table('teacher_profiles')->where('user_id', $u->id)->first() : null;
echo json_encode([
 'webhooks_by_status' => $g('payment_webhooks', 'status'),
 'webhook_events' => DB::table('payment_webhooks')->orderByDesc('id')->limit(10)->get(['event_id','event_type','status','error','created_at'])->all(),
 'orders_by_status' => $g('payment_orders', 'status'),
 'recharges_by_status' => $g('recharge_requests', 'status'),
 'ledger' => $p ? DB::table('credit_transactions')->where('teacher_profile_id', $p->id)->get(['type','amount','idempotency_key'])->all() : [],
 'balance' => $p ? [$p->credits_available, $p->credits_reserved] : null,
 'deposits_for_evaluator' => $p ? DB::table('credit_transactions')->where('teacher_profile_id', $p->id)->where('type','deposit')->count() : null,
 'jobs_pending' => DB::table('jobs')->count(), 'jobs_failed' => DB::table('failed_jobs')->count(),
 'legal_acceptances' => $u ? DB::table('legal_acceptances')->where('user_id', $u->id)->count() : null,
 'journal_mode' => DB::selectOne('PRAGMA journal_mode')->journal_mode,
 'integrity' => DB::selectOne('PRAGMA integrity_check')->integrity_check,
], JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT), "\n";
PHP
php /tmp/bk.php || true
(while true; do sleep 30; php /tmp/bk.php || true; done) &
(while true; do php artisan queue:work --queue=default --sleep=3 --tries=3 --timeout=60 --backoff=5 --no-interaction; sleep 5; done) &
(while true; do php artisan schedule:work; sleep 5; done) &
mova-entrypoint frankenphp run --config /etc/caddy/Caddyfile &
APP=$!
trap 'php /tmp/bk.php || true; kill -TERM $APP; wait $APP; exit 0' TERM INT
wait $APP
