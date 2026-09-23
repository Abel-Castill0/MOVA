<?php

namespace App\Console\Commands;

use App\Models\OperationalAlert;
use App\Services\OperationalAlertService;
use App\Support\Heartbeat;
use App\Support\ProviderGuard;
use App\Support\SettlementMode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * F-02 / F-03 — Detecta configuraciones que son válidas para arrancar pero
 * peligrosas para operar. Nace de dos hallazgos de la auditoría de Fase 2:
 *
 *   - C-1 llevaba meses agendado con --dry-run hardcodeado: el sistema
 *     detectaba cada hora lo que debía liquidar y no liquidaba nada, sin que
 *     nada lo señalara. 248 tests en verde no lo detectaron.
 *   - Los proveedores externos caían silenciosamente en Fake ante cualquier
 *     typo, reportando éxito sin cobrar ni enviar nada.
 *
 * Solo lectura. Exit code 1 si hay algo que requiere atención humana, para que
 * pueda engancharse a un monitor o a un paso de despliegue.
 */
class HealthCheck extends Command
{
    protected $signature = 'mova:health-check
        {--json : Salida en JSON para monitorización}
        {--alert : Además de informar, registra las incidencias para que los administradores reciban aviso (lo usa el scheduler)}';

    /**
     * H-03 — Severidad por código de aviso.
     *
     * Todo lo que este comando emite ya es, por definición, "válido para
     * arrancar pero peligroso para operar", así que el default es CRÍTICO. La
     * única excepción es el backlog de la cola: que existan jobs fallidos es
     * información operativa que hay que revisar, no una configuración rota.
     */
    private const NON_CRITICAL_CODES = [
        'QUEUE_FAILED_JOBS',
    ];

    protected $description = 'Revisa configuraciones válidas pero operacionalmente peligrosas';

    public function handle(): int
    {
        $environment = app()->environment();
        $warnings    = [];
        $checks      = [];
        // Visibilidad sin bloquear el arranque: "IA apagada" o "70% de
        // adopción de opt-in" no son configuraciones peligrosas, son estado
        // operativo que alguien puede querer ver sin que rompa un despliegue
        // en local/testing. Nunca afectan a $healthy ni al exit code.
        $info = [];

        // ── C-1: liquidación automática ──────────────────────────────────
        try {
            $settlementMode = SettlementMode::current();
            $checks['settlement_mode'] = $settlementMode;

            if (SettlementMode::isDangerousInProduction($environment)) {
                $warnings[] = [
                    'code'    => 'SETTLEMENT_DRY_RUN_IN_PRODUCTION',
                    'message' => 'La liquidación automática (C-1) está en dry_run en producción: los créditos de '
                        .'clases que nadie cierra quedan reservados indefinidamente. Revisa la salida de '
                        .'`php artisan mova:settle-lessons --dry-run` y activa LESSON_SETTLEMENT_MODE=live.',
                ];
            }
        } catch (\Throwable $e) {
            $checks['settlement_mode'] = 'INVÁLIDO';
            $warnings[] = ['code' => 'SETTLEMENT_MODE_INVALID', 'message' => $e->getMessage()];
        }

        // ── Proveedores externos ─────────────────────────────────────────
        // PROVIDER SOURCE OF TRUTH (MOVA Yape Checkout Pre-Card Hardening):
        // la lista de pagos ya no es una copia hardcodeada propia — se lee
        // de config('payments.supported_providers'), la MISMA que usa
        // AppServiceProvider::register() (ProviderGuard::resolve()). Antes
        // esta lista vivía duplicada aquí y ya había divergido una vez
        // (se quedó con solo 'culqi' cuando se agregó Mercado Pago): un
        // falso positivo "INVÁLIDO" en mova:health-check pese a un binding
        // de contenedor perfectamente válido.
        foreach ([
            ['pagos', 'PAYMENT_PROVIDER', 'payments.provider', config('payments.supported_providers'), 'payments.enabled'],
            ['WhatsApp', 'WHATSAPP_PROVIDER', 'services.whatsapp.provider', ['meta'], 'services.whatsapp.enabled'],
        ] as [$kind, $envVar, $providerKey, $supported, $enabledKey]) {
            $featureEnabled = (bool) config($enabledKey, false);

            try {
                $resolved = ProviderGuard::resolve(
                    $kind, $envVar, config($providerKey), $supported, $featureEnabled, $environment
                );
                $checks[$envVar] = $resolved.($featureEnabled ? ' (habilitado)' : ' (deshabilitado)');
            } catch (\Throwable $e) {
                $checks[$envVar] = 'INVÁLIDO';
                $warnings[] = ['code' => $envVar.'_INVALID', 'message' => $e->getMessage()];
            }
        }

        // ── F-04: el despacho debe poder revertirse con el claim ─────────
        // SendClassReminders reclama la lección y despacha el aviso dentro de
        // una misma transacción, para que un fallo libere el marcador en vez de
        // consumirlo sin haber avisado.
        //
        // Drivers compatibles:
        //   database -> el job es un INSERT en la misma conexión: entra en el rollback.
        //   sync     -> el envío ocurre inline dentro de la transacción: si falla,
        //               lanza y todo se revierte igualmente.
        //
        // Drivers que ROMPEN la premisa: redis, sqs, beanstalkd... el job sale
        // del ámbito transaccional, así que un fallo posterior deja el marcador
        // consumido sin nada encolado.
        $queueConnection = config('queue.default');
        $queueDriver     = config("queue.connections.{$queueConnection}.driver");
        $checks['queue_driver'] = (string) $queueDriver;

        if (!in_array($queueDriver, ['database', 'sync'], true)) {
            $warnings[] = [
                'code'    => 'QUEUE_NOT_TRANSACTIONAL',
                'message' => "La cola usa el driver \"{$queueDriver}\". El claim de recordatorios "
                    .'(SendClassReminders::claimAndDispatch) depende de que el despacho pueda revertirse '
                    .'junto al marcador; con este driver el job sale de la transacción, así que un fallo '
                    .'posterior consumiría el marcador sin encolar el aviso y se perdería. '
                    .'Usa "database" o rediseña el claim con lease/expiración.',
            ];
        }

        // PRODUCTION ENABLEMENT (readiness pass): "sync" es válido para el
        // chequeo de arriba (SendClassReminders), pero rompe una premisa
        // DISTINTA del webhook de Mercado Pago: MercadoPagoWebhookController
        // encola ProcessMercadoPagoWebhook precisamente para responder
        // rápido y nunca hacer el trabajo financiero (consulta a Mercado
        // Pago, acreditación) dentro del ciclo de la request HTTP. Con
        // QUEUE_CONNECTION=sync (el default de config/queue.php si la
        // variable falta en Railway — ver docs/DEPLOY_RAILWAY.md), ese job
        // se ejecuta INLINE dentro del propio POST del webhook: el ACK deja
        // de ser rápido y un GET lento/caído a Mercado Pago retiene la
        // respuesta al webhook en vez de solo el reintento del job.
        if ($queueDriver === 'sync'
            && (bool) config('payments.enabled', false)
            && config('payments.provider') === 'mercadopago'
            && (bool) config('payments.mercadopago.webhooks_enabled', false)
        ) {
            $warnings[] = [
                'code'    => 'MERCADOPAGO_WEBHOOK_QUEUE_SYNC',
                'message' => 'QUEUE_CONNECTION=sync con el webhook de Mercado Pago habilitado: '
                    .'ProcessMercadoPagoWebhook se ejecutaría dentro del propio request HTTP del webhook '
                    .'en vez de en el worker, perdiendo el ACK rápido y desacoplado que exige el diseño '
                    .'del endpoint. Configura QUEUE_CONNECTION=database en Railway.',
            ];
        }

        // PRODUCTION ENABLEMENT (Railpack reality check): withoutOverlapping()
        // (classmate:send-reminders, mova:settle-lessons, mercadopago:reconcile
        // — ver app/Console/Kernel.php) usa como mutex el cache store por
        // defecto. Eso SOLO es un lock compartido entre procesos/réplicas si
        // el store es realmente compartido (database/redis) — el default de
        // config/cache.php (y de .env.example) es 'file', local al
        // contenedor. En 'file' el lock sigue siendo válido dentro de un
        // mismo proceso/contenedor (protege contra que schedule:work se
        // solape consigo mismo), pero NO protege si mova-scheduler llegara a
        // escalar a más de una réplica.
        //
        // CORREGIDO (Railpack runtime final gate): esto es SOLO informativo,
        // nunca falla el health-check. La arquitectura de producción
        // actualmente aceptada es CACHE_DRIVER=file + mova-scheduler en
        // EXACTAMENTE 1 réplica (ver docs/DEPLOY_RAILWAY.md) — eso es una
        // configuración válida y suficiente, no una que deba bloquear un
        // despliegue o un gate de monitorización. 'file' en producción no
        // es, por sí solo, evidencia de un problema: lo sería únicamente
        // combinado con más de una réplica, y eso no es observable desde
        // dentro de la aplicación.
        $cacheStore  = config('cache.default');
        $cacheDriver = config("cache.stores.{$cacheStore}.driver");
        $checks['cache_driver'] = (string) $cacheDriver;

        $info['scheduler_lock_shared'] = in_array($cacheDriver, ['database', 'redis'], true)
            ? "sí (CACHE_DRIVER=\"{$cacheDriver}\")"
            : "no (CACHE_DRIVER=\"{$cacheDriver}\") — válido siempre que mova-scheduler corra con exactamente 1 réplica";

        // ── F-20: el timeout del worker debe ser MENOR que retry_after ───
        // Si son iguales (lo estaban: ambos 90), un job que se acerca a su
        // límite puede liberarse a la cola y ser recogido por un segundo worker
        // mientras el primero sigue vivo — el mismo mensaje sale dos veces.
        $retryAfter = (int) config("queue.connections.{$queueConnection}.retry_after", 0);
        $workerTimeout = $this->workerTimeoutFromDeployConfig();

        if ($retryAfter > 0 && $workerTimeout !== null) {
            $checks['worker_timeout_vs_retry_after'] = "{$workerTimeout}s / {$retryAfter}s";

            if ($workerTimeout >= $retryAfter) {
                $warnings[] = [
                    'code' => 'QUEUE_TIMEOUT_COLLISION',
                    'message' => "El worker usa --timeout={$workerTimeout} y retry_after={$retryAfter}. El timeout "
                        .'DEBE ser estrictamente menor, o un job cercano a su límite puede ejecutarse en dos '
                        .'workers a la vez y duplicar el mensaje. Revisa railway.queue.toml.',
                ];
            }
        }

        // ── Matriz de timeouts: HTTP externo < worker < retry_after ──────
        // Ningún Job/Listener/Notification declara $timeout propio (verificado
        // por búsqueda global), así que todos heredan el del worker. El único
        // riesgo real es que un Http::timeout(N) saliente exceda ese valor: si
        // Meta/OpenAI/Gmail tardaran más que el worker, el proceso moriría a
        // mitad de la llamada. Los valores declarados hoy están muy por debajo
        // (8-15s vs 60s), pero esto lo vuelve a comprobar en cada despliegue en
        // vez de confiar en que nadie lo suba sin revisar la relación.
        $httpTimeouts = [
            'MetaCloudApiProvider (WhatsApp)' => 10,
            'DiagnosticAiEnrichmentService (IA)' => (int) config('diagnostic.timeout_seconds', 8),
            'GmailApiMailService' => 15,
        ];
        $maxHttpTimeout = max($httpTimeouts);
        $checks['max_outbound_http_timeout'] = "{$maxHttpTimeout}s";

        if ($workerTimeout !== null && $maxHttpTimeout >= $workerTimeout) {
            $offender = array_search($maxHttpTimeout, $httpTimeouts, true);
            $warnings[] = [
                'code' => 'HTTP_TIMEOUT_EXCEEDS_WORKER',
                'message' => "{$offender} usa un timeout HTTP de {$maxHttpTimeout}s, igual o mayor que el timeout "
                    .'del worker ('."{$workerTimeout}s".'). El worker mataría el proceso a mitad de la llamada '
                    .'externa. Baja el timeout HTTP o sube el del worker (y retry_after junto con él).',
            ];
        }

        // ── Observabilidad de la cola ────────────────────────────────────
        try {
            $pending = DB::table('jobs')->count();
            $failed = DB::table('failed_jobs')->count();
            $checks['queue_backlog'] = "{$pending} pendientes / {$failed} fallidos";

            if ($failed > 0) {
                $warnings[] = [
                    'code' => 'QUEUE_FAILED_JOBS',
                    'message' => "Hay {$failed} job(s) en failed_jobs. Cada uno es una notificación que nunca "
                        .'llegó a su destinatario. Revísalos con `php artisan queue:failed`.',
                ];
            }
        } catch (\Throwable $e) {
            $checks['queue_backlog'] = 'no disponible';
        }

        // ── P0-K: datos del proveedor del Libro de Reclamaciones ────────
        // No se inventan: si faltan, el formulario muestra "pendiente" y
        // aquí queda una incidencia hasta que el titular los configure.
        if ($environment === 'production') {
            $missing = collect(['LEGAL_BUSINESS_NAME' => 'business_name', 'LEGAL_RUC' => 'ruc', 'LEGAL_ADDRESS' => 'address'])
                ->filter(fn ($key) => blank(config("legal.provider.{$key}")))
                ->keys();
            if ($missing->isNotEmpty()) {
                $warnings[] = [
                    'code' => 'LEGAL_PROVIDER_DATA_MISSING',
                    'message' => 'El Libro de Reclamaciones no muestra los datos del proveedor: faltan '.$missing->implode(', ').'.',
                ];
            }
        }

        // ── P0-J: latido del worker ──────────────────────────────────────
        // Este comando corre DENTRO del scheduler, así que no puede detectar
        // un scheduler caído (eso lo ve el Centro de Operaciones); sí detecta
        // un worker que no consume la cola. Solo en producción: en local es
        // normal no tener worker.
        if ($environment === 'production') {
            try {
                if (Heartbeat::status(Heartbeat::WORKER) !== 'healthy') {
                    $warnings[] = [
                        'code' => 'WORKER_HEARTBEAT_STALE',
                        'message' => 'El worker de cola no ha procesado el latido en los últimos '
                            .intdiv(Heartbeat::STALE_AFTER_SECONDS, 60).' min: los jobs (correos, webhooks) no se están ejecutando.',
                    ];
                }
            } catch (\Throwable $e) {
                $checks['worker_heartbeat'] = 'no disponible';
            }
        }

        // ── APP_DEBUG en producción: fuga de información real ────────────
        // Con APP_DEBUG=true, un 500 muestra stack trace, rutas del
        // servidor y variables de entorno a cualquier visitante. En una
        // plataforma con datos de menores, esto SÍ es un ERROR, no una nota.
        if ($environment === 'production' && config('app.debug') === true) {
            $warnings[] = [
                'code' => 'APP_DEBUG_IN_PRODUCTION',
                'message' => 'APP_DEBUG=true en producción expone stack traces, rutas del servidor y variables '
                    .'de entorno en cualquier error 500. Ponlo en false.',
            ];
        }
        $checks['app_debug'] = config('app.debug') ? 'true' : 'false';

        // ── Informativos: IA, JaaS, Culqi, adopción de WhatsApp ───────────
        $info['ai_enabled'] = (bool) config('diagnostic.ai_enabled', false)
            ? 'habilitada ('.config('diagnostic.ai_provider', 'openai').')'
            : 'deshabilitada';

        $info['jaas_configured'] = (config('jaas.app_id') && config('jaas.private_key') && config('jaas.key_id'))
            ? 'credenciales presentes' : 'sin configurar';

        $info['culqi_configured'] = (config('payments.culqi.public_key') && config('payments.culqi.private_key'))
            ? 'credenciales presentes' : 'sin configurar';

        // Denominadores explícitos a propósito: "70% de adopción" no dice nada
        // sin decir 70% de QUÉ. Un usuario sin teléfono verificado no puede
        // ni ver el toggle de opt-in (vive en las preferencias del perfil,
        // gateado por verificación), así que el universo relevante de
        // adopción es "verificados", no "todos los usuarios".
        try {
            $totalUsers = DB::table('users')->count();
            $verifiedPhones = DB::table('users')->whereNotNull('phone_verified_at')->count();
            $optedIn = DB::table('users')->whereNotNull('whatsapp_opt_in_at')->whereNull('whatsapp_opt_out_at')->count();
            $optedOut = DB::table('users')->whereNotNull('whatsapp_opt_out_at')->count();
            $adoptionRate = $verifiedPhones > 0 ? round(($optedIn / $verifiedPhones) * 100, 1) : 0.0;

            $info['whatsapp_adoption'] = "{$optedIn}/{$verifiedPhones} verificados con opt-in activo ({$adoptionRate}%) — "
                ."{$optedOut} opt-out — {$verifiedPhones}/{$totalUsers} usuarios con teléfono verificado";
        } catch (\Throwable $e) {
            $info['whatsapp_adoption'] = 'no disponible';
        }

        $healthy = $warnings === [];

        $this->publishFindings($warnings, $environment);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'environment' => $environment,
                'checks'      => $checks,
                'info'        => $info,
                'warnings'    => $warnings,
                'healthy'     => $healthy,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $healthy ? self::SUCCESS : self::FAILURE;
        }

        $this->info("Entorno: {$environment}");
        foreach ($checks as $key => $value) {
            $this->line("  {$key}: {$value}");
        }
        $this->newLine();
        $this->line('  <fg=gray>-- informativo, no afecta al estado --</>');
        foreach ($info as $key => $value) {
            $this->line("  {$key}: {$value}");
        }

        if ($healthy) {
            $this->newLine();
            $this->info('GREEN — no hay configuraciones peligrosas.');

            return self::SUCCESS;
        }

        $this->newLine();
        foreach ($warnings as $warning) {
            $this->warn("[{$warning['code']}] {$warning['message']}");
        }

        return self::FAILURE;
    }

    /**
     * H-03 — Hace que el resultado salga de la consola.
     *
     * Antes este comando escribía todo con `$this->warn()`. Agendado, esa salida
     * no la lee nadie: el hallazgo original era precisamente que MOVA sabía
     * detectar `LESSON_SETTLEMENT_MODE=dry_run` en producción y ese aviso no
     * llegaba a ninguna parte.
     *
     * Dos canales, con propósitos distintos:
     *
     *   - LOG ESTRUCTURADO, siempre. Es barato, y desde H-01 un `Log::error`
     *     no reemplaza a Sentry pero sí queda en la traza del contenedor.
     *
     *   - INCIDENCIAS (`--alert`), solo cuando lo pide el llamador. El
     *     scheduler lo pasa; una ejecución manual en local NO, para que
     *     depurar configuración no llene la bandeja de los administradores de
     *     incidencias que no existen en producción.
     *
     * Cuando un aviso DESAPARECE, su incidencia se resuelve. Así el panel
     * refleja el estado actual y no un histórico, y si el problema vuelve, se
     * vuelve a avisar.
     *
     * @param  array<int, array{code:string,message:string}>  $warnings
     */
    private function publishFindings(array $warnings, string $environment): void
    {
        $codesSeen = [];

        foreach ($warnings as $warning) {
            $isCritical = ! in_array($warning['code'], self::NON_CRITICAL_CODES, true);
            $codesSeen[] = $warning['code'];

            Log::log($isCritical ? 'error' : 'warning', '[HealthCheck] '.$warning['code'], [
                'code' => $warning['code'],
                'message' => $warning['message'],
                'environment' => $environment,
            ]);
        }

        if (! $this->option('alert')) {
            return;
        }

        $alerts = app(OperationalAlertService::class);

        foreach ($warnings as $warning) {
            $isCritical = ! in_array($warning['code'], self::NON_CRITICAL_CODES, true);

            $alerts->raise(
                key: 'health:'.$warning['code'],
                type: OperationalAlert::TYPE_HEALTH_CHECK,
                title: 'Configuración peligrosa: '.$warning['code'],
                message: $warning['message'],
                context: ['Entorno' => $environment],
                severity: $isCritical
                    ? OperationalAlert::SEVERITY_CRITICAL
                    : OperationalAlert::SEVERITY_WARNING,
            );
        }

        // Cierra las incidencias de health-check que ya no se reproducen.
        // Se consultan solo las abiertas de este tipo: son unas pocas filas.
        OperationalAlert::query()
            ->open()
            ->where('type', OperationalAlert::TYPE_HEALTH_CHECK)
            ->pluck('alert_key')
            ->reject(fn (string $key) => in_array(substr($key, strlen('health:')), $codesSeen, true))
            ->each(fn (string $key) => $alerts->resolve($key));
    }
    /**
     * Extrae `--timeout=N` del comando de arranque del worker declarado en
     * railway.queue.toml. Se lee del fichero y no de la configuración de
     * Laravel porque ese valor NO vive en config/queue.php: es un argumento del
     * proceso, y precisamente por estar en otro sitio se desvió de su propia
     * documentación sin que nada lo detectara.
     */
    private function workerTimeoutFromDeployConfig(): ?int
    {
        $path = base_path('railway.queue.toml');

        if (!is_file($path)) {
            return null;
        }

        if (!preg_match('/--timeout=(\d+)/', (string) file_get_contents($path), $m)) {
            return null;
        }

        return (int) $m[1];
    }
}
