<?php

namespace Tests\Feature;

use App\Support\SettlementMode;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * F-02 / GAP-01 — La suite tenía 248 tests en verde y ninguno miraba el
 * scheduler. Por eso nadie detectó que `mova:settle-lessons` llevaba agendado
 * con --dry-run hardcodeado, dejando C-1 permanentemente inactivo en
 * producción. Estos tests cierran ese punto ciego.
 *
 * RefreshDatabase aísla los datos. Los fallos de consulta se inyectan en
 * el query builder: DROP TABLE hace commit implícito en MySQL y destruye
 * la transacción de aislamiento de toda la suite.
 */
class SchedulerConfigurationTest extends TestCase
{
    use RefreshDatabase;

    /** @return string[] */
    private function scheduledCommands(): array
    {
        // Se reconstruye el Schedule desde cero para que lea la config actual
        // del test, no la que se resolvió al bootear la aplicación.
        $schedule = new Schedule();
        (new \ReflectionMethod(\App\Console\Kernel::class, 'schedule'))
            ->invoke($this->app->make(\App\Console\Kernel::class), $schedule);

        return array_map(fn (Event $event) => $event->command ?? '', $schedule->events());
    }

    private function findScheduled(string $needle): Event
    {
        $schedule = new Schedule();
        (new \ReflectionMethod(\App\Console\Kernel::class, 'schedule'))
            ->invoke($this->app->make(\App\Console\Kernel::class), $schedule);

        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, $needle)) {
                return $event;
            }
        }

        $this->fail("No se encontró ningún comando agendado que contenga «{$needle}».");
    }

    // ── El modo configurado gobierna el scheduler ────────────────────────

    public function test_dry_run_mode_schedules_the_command_with_the_dry_run_flag(): void
    {
        config(['credits.settlement_mode' => SettlementMode::DRY_RUN]);

        $this->assertStringContainsString('--dry-run', $this->findScheduled('mova:settle-lessons')->command);
    }

    public function test_live_mode_schedules_the_command_without_the_dry_run_flag(): void
    {
        config(['credits.settlement_mode' => SettlementMode::LIVE]);

        $this->assertStringNotContainsString('--dry-run', $this->findScheduled('mova:settle-lessons')->command);
    }

    public function test_the_default_mode_is_dry_run_so_activation_stays_a_deliberate_decision(): void
    {
        // El valor por defecto NO debe cambiar sin decisión de negocio: activar
        // la liquidación real por accidente sería peor que tenerla apagada.
        $this->assertSame(SettlementMode::DRY_RUN, config('credits.settlement_mode'));
    }

    public function test_an_invalid_settlement_mode_aborts_instead_of_guessing(): void
    {
        config(['credits.settlement_mode' => 'live-ish']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no es válido/');

        SettlementMode::current();
    }

    // ── Protección contra solapamiento ───────────────────────────────────

    public function test_settle_lessons_is_protected_against_overlapping_runs(): void
    {
        $this->assertTrue(
            $this->findScheduled('mova:settle-lessons')->withoutOverlapping,
            'mova:settle-lessons debe usar withoutOverlapping().'
        );
    }

    public function test_send_reminders_is_protected_against_overlapping_runs(): void
    {
        // F-04: corre cada minuto y sus barridos pueden superar los 60s con
        // volumen real. Sin esto, dos instancias envían el mismo recordatorio.
        $this->assertTrue(
            $this->findScheduled('classmate:send-reminders')->withoutOverlapping,
            'classmate:send-reminders debe usar withoutOverlapping().'
        );
    }

    public function test_the_expected_commands_are_actually_scheduled(): void
    {
        config(['payments.enabled' => true, 'payments.provider' => 'mercadopago']);

        $commands = implode(' | ', $this->scheduledCommands());

        $this->assertStringContainsString('classmate:send-reminders', $commands);
        $this->assertStringContainsString('mova:settle-lessons', $commands);
        $this->assertStringContainsString('mercadopago:reconcile', $commands);
    }

    // AZ-3E — agendarlo incondicionalmente no tenía sentido con pagos
    // apagados o un provider distinto: nada que reconciliar, y un barrido
    // HTTP cada 5 minutos contra un proveedor apagado. El guard lee
    // config('payments.enabled')/config('payments.provider'), no el estado
    // (vacío o no) de payment_orders/payment_webhooks -- esas tablas vacías
    // son un efecto observado, no la causa real que el guard debe mirar.
    public function test_mercadopago_reconcile_is_not_scheduled_when_payments_are_disabled(): void
    {
        config(['payments.enabled' => false, 'payments.provider' => 'mercadopago']);

        $commands = implode(' | ', $this->scheduledCommands());

        $this->assertStringNotContainsString('mercadopago:reconcile', $commands);
    }

    public function test_mercadopago_reconcile_is_not_scheduled_when_the_provider_is_not_mercadopago(): void
    {
        config(['payments.enabled' => true, 'payments.provider' => 'fake']);

        $commands = implode(' | ', $this->scheduledCommands());

        $this->assertStringNotContainsString('mercadopago:reconcile', $commands);
    }

    public function test_mercadopago_reconcile_is_scheduled_when_payments_are_enabled_with_mercadopago(): void
    {
        config(['payments.enabled' => true, 'payments.provider' => 'mercadopago']);

        $commands = implode(' | ', $this->scheduledCommands());

        $this->assertStringContainsString('mercadopago:reconcile', $commands);
    }

    // PRODUCTION ENABLEMENT (readiness pass) — mercadopago:reconcile era un
    // PRODUCTION BLOCKER explícito (ver docblock de
    // App\Console\Commands\MercadoPagoReconcile) mientras no estuviera
    // agendado: la recuperación de webhooks/pagos atascados solo corría si
    // alguien la ejecutaba a mano. Este test fija que, con pagos habilitados
    // en mercadopago, quede agendado y protegido, igual que los otros dos
    // comandos de arriba.
    public function test_mercadopago_reconcile_is_scheduled_and_protected_against_overlapping(): void
    {
        config(['payments.enabled' => true, 'payments.provider' => 'mercadopago']);

        $this->assertTrue(
            $this->findScheduled('mercadopago:reconcile')->withoutOverlapping,
            'mercadopago:reconcile debe usar withoutOverlapping().'
        );
    }

    // PAYMENT SCHEDULER ISOLATION GATE — inspecciona las propiedades reales
    // del Event generado (Illuminate\Console\Scheduling\Event), no solo el
    // string del comando: cadencia exacta, expiry corregido del mutex, y
    // runInBackground() para que un barrido largo no bloquee
    // classmate:send-reminders/mova:settle-lessons dentro del mismo
    // schedule:run (ver comentario en app/Console/Kernel.php).
    public function test_mercadopago_reconcile_runs_every_five_minutes_in_background_with_the_corrected_expiry(): void
    {
        config(['payments.enabled' => true, 'payments.provider' => 'mercadopago']);

        $event = $this->findScheduled('mercadopago:reconcile');

        $this->assertSame('*/5 * * * *', $event->expression, 'debe correr cada 5 minutos.');
        $this->assertTrue($event->withoutOverlapping, 'debe seguir protegido contra solaparse consigo mismo.');
        $this->assertSame(
            900,
            $event->expiresAt,
            'expiry del mutex: 900 min, con margen sobre la cota de ~555 min derivada de retry(2,300) = 2 intentos.'
        );
        $this->assertTrue(
            $event->runInBackground,
            'debe correr en background para no bloquear otros eventos agendados en el mismo schedule:run.'
        );
    }

    // Prueba negativa explícita del riesgo que runInBackground() cierra:
    // classmate:send-reminders y mova:settle-lessons deben seguir agendados
    // en foreground (bloquear intencionalmente NO es un problema para
    // ellos — corren rápido) y sin verse afectados por el cambio anterior.
    public function test_reminders_and_settlement_remain_scheduled_in_foreground_unaffected_by_reconcile(): void
    {
        $reminders = $this->findScheduled('classmate:send-reminders');
        $this->assertSame('* * * * *', $reminders->expression);
        $this->assertTrue($reminders->withoutOverlapping);
        $this->assertFalse($reminders->runInBackground, 'no necesita background — no debe cambiar sin motivo.');

        $settlement = $this->findScheduled('mova:settle-lessons');
        $this->assertSame('0 * * * *', $settlement->expression);
        $this->assertTrue($settlement->withoutOverlapping);
        $this->assertFalse($settlement->runInBackground, 'no necesita background — no debe cambiar sin motivo.');
    }

    // ── Health check de configuración peligrosa ──────────────────────────

    public function test_health_check_flags_dry_run_settlement_as_dangerous_in_production(): void
    {
        $this->assertTrue(
            SettlementMode::isDangerousInProduction('production'),
            'production + dry_run debe reportarse como configuración peligrosa.'
        );
    }

    public function test_health_check_does_not_flag_dry_run_outside_production(): void
    {
        $this->assertFalse(SettlementMode::isDangerousInProduction('local'));
        $this->assertFalse(SettlementMode::isDangerousInProduction('testing'));
    }

    public function test_live_settlement_in_production_is_not_flagged(): void
    {
        config(['credits.settlement_mode' => SettlementMode::LIVE]);

        $this->assertFalse(SettlementMode::isDangerousInProduction('production'));
    }

    public function test_health_check_command_reports_green_under_the_test_environment(): void
    {
        $this->artisan('mova:health-check')->assertExitCode(0);
    }

    public function test_health_check_command_fails_when_a_provider_is_misconfigured(): void
    {
        config(['services.whatsapp.provider' => 'twilio']);

        $this->artisan('mova:health-check')->assertExitCode(1);
    }

    // PRODUCTION ENABLEMENT (readiness pass) — QUEUE_CONNECTION=sync es
    // válido para el chequeo QUEUE_NOT_TRANSACTIONAL (SendClassReminders),
    // pero rompe una premisa distinta: el webhook de Mercado Pago encola
    // ProcessMercadoPagoWebhook precisamente para responder rápido y nunca
    // hacer el trabajo financiero dentro del ciclo de la request. Con
    // sync, ese job correría inline en el propio POST del webhook.
    public function test_health_check_flags_sync_queue_when_mercadopago_webhook_is_enabled(): void
    {
        config([
            'queue.default' => 'sync',
            'payments.enabled' => true,
            'payments.provider' => 'mercadopago',
            'payments.mercadopago.webhooks_enabled' => true,
        ]);

        $this->artisan('mova:health-check')
            ->expectsOutputToContain('MERCADOPAGO_WEBHOOK_QUEUE_SYNC')
            ->assertExitCode(1);
    }

    public function test_health_check_does_not_flag_sync_queue_when_mercadopago_webhook_is_disabled(): void
    {
        // MERCADOPAGO_WEBHOOKS_ENABLED=false es el default seguro (el
        // endpoint ya rechaza todo sin importar la firma) — sync no es
        // peligroso todavía porque ProcessMercadoPagoWebhook no puede
        // encolarse en absoluto mientras el webhook siga inerte.
        config([
            'queue.default' => 'sync',
            'payments.enabled' => true,
            'payments.provider' => 'mercadopago',
            'payments.mercadopago.webhooks_enabled' => false,
        ]);

        $this->artisan('mova:health-check')->assertExitCode(0);
    }

    // PRODUCTION ENABLEMENT (Railpack runtime final gate) — CACHE_DRIVER=file
    // + mova-scheduler en exactamente 1 réplica es la arquitectura de
    // producción actualmente ACEPTADA (ver docs/DEPLOY_RAILWAY.md), no una
    // configuración peligrosa. El estado del lock del scheduler es
    // puramente informativo — nunca debe tumbar un health-check por sí
    // solo, ni siquiera en producción.
    public function test_health_check_reports_non_shared_cache_as_informational_only_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['cache.default' => 'array']);

        $bufferedOutput = new \Symfony\Component\Console\Output\BufferedOutput();
        $outputStyle = new \Illuminate\Console\OutputStyle(
            new \Symfony\Component\Console\Input\ArrayInput(['--json' => true]),
            $bufferedOutput
        );
        \Illuminate\Support\Facades\Artisan::call('mova:health-check', ['--json' => true], $outputStyle);
        $decoded = json_decode($bufferedOutput->fetch(), true);

        $codes = array_column($decoded['warnings'], 'code');
        $this->assertNotContains('SCHEDULER_LOCK_NOT_SHARED', $codes, 'el estado del cache store no debe ser un warning que bloquea.');
        $this->assertSame('array', $decoded['checks']['cache_driver']);
        $this->assertStringContainsString('no (CACHE_DRIVER="array")', $decoded['info']['scheduler_lock_shared']);
    }

    public function test_health_check_reports_shared_cache_informationally(): void
    {
        $this->app['env'] = 'production';
        config(['cache.default' => 'database']);

        $bufferedOutput = new \Symfony\Component\Console\Output\BufferedOutput();
        $outputStyle = new \Illuminate\Console\OutputStyle(
            new \Symfony\Component\Console\Input\ArrayInput(['--json' => true]),
            $bufferedOutput
        );
        \Illuminate\Support\Facades\Artisan::call('mova:health-check', ['--json' => true], $outputStyle);
        $decoded = json_decode($bufferedOutput->fetch(), true);

        $this->assertSame('database', $decoded['checks']['cache_driver']);
        $this->assertStringContainsString('sí (CACHE_DRIVER="database")', $decoded['info']['scheduler_lock_shared']);
    }

    public function test_informational_checks_never_affect_the_exit_code_covers_scheduler_lock(): void
    {
        // Reafirma explícitamente para este check nuevo la garantía general
        // ya cubierta por test_informational_checks_never_affect_the_exit_code:
        // nada dentro de $info puede tumbar mova:health-check por sí solo,
        // ni siquiera con la combinación más adversa (producción + cache no
        // compartida + settlement en live, para aislar de otros warnings
        // conocidos).
        $this->app['env'] = 'production';
        config([
            'cache.default' => 'array',
            'credits.settlement_mode' => SettlementMode::LIVE,
            // Aislar también de los avisos de producción P0-J/P0-K.
            'legal.provider.business_name' => 'X', 'legal.provider.ruc' => '1', 'legal.provider.address' => 'X',
        ]);
        \App\Support\Heartbeat::beat(\App\Support\Heartbeat::WORKER);
        \App\Support\Heartbeat::beat(\App\Support\Heartbeat::SCHEDULER);

        $bufferedOutput = new \Symfony\Component\Console\Output\BufferedOutput();
        $outputStyle = new \Illuminate\Console\OutputStyle(
            new \Symfony\Component\Console\Input\ArrayInput(['--json' => true]),
            $bufferedOutput
        );
        $exitCode = \Illuminate\Support\Facades\Artisan::call('mova:health-check', ['--json' => true], $outputStyle);
        $decoded = json_decode($bufferedOutput->fetch(), true);

        $this->assertNotContains('SCHEDULER_LOCK_NOT_SHARED', array_column($decoded['warnings'], 'code'));
        $this->assertSame(0, $exitCode, 'un cache store no compartido en producción no debe hacer fallar el health-check por sí solo.');
    }

    // ── APP_DEBUG: peligroso solo en producción ───────────────────────────

    public function test_app_debug_true_in_production_fails_the_health_check(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => true]);

        $this->artisan('mova:health-check')
            ->expectsOutputToContain('APP_DEBUG_IN_PRODUCTION')
            ->assertExitCode(1);
    }

    public function test_app_debug_true_outside_production_is_not_a_false_positive(): void
    {
        // local/testing con APP_DEBUG=true es normal y no debe romper el
        // arranque — el health-check no debe generar ruido en desarrollo.
        config(['app.debug' => true]);

        $this->artisan('mova:health-check')->assertExitCode(0);
    }

    // ── Los informativos no afectan al estado ─────────────────────────────

    public function test_informational_checks_never_affect_the_exit_code(): void
    {
        config(['diagnostic.ai_enabled' => false]);

        $this->artisan('mova:health-check --json')->assertExitCode(0);
    }

    public function test_whatsapp_adoption_reports_explicit_numerators_and_denominators(): void
    {
        // "70% de adopción" no dice nada sin decir 70% de qué. Se crean 3
        // usuarios con teléfono verificado (2 con opt-in activo, 1 en baja) y
        // 1 usuario sin verificar, para que el string deba mostrar
        // explícitamente 2/3 (67%) sobre el universo correcto (verificados),
        // no sobre el total de usuarios (4).
        \App\Models\User::factory()->create([
            'phone_verified_at' => now(),
            'phone_verified_normalized' => '+51900000001',
            'whatsapp_opt_in_at' => now(),
        ]);
        \App\Models\User::factory()->create([
            'phone_verified_at' => now(),
            'phone_verified_normalized' => '+51900000002',
            'whatsapp_opt_in_at' => now(),
        ]);
        \App\Models\User::factory()->create([
            'phone_verified_at' => now(),
            'phone_verified_normalized' => '+51900000003',
            'whatsapp_opt_in_at' => now(),
            'whatsapp_opt_out_at' => now(),
        ]);
        \App\Models\User::factory()->create([
            'phone_verified_at' => null,
            'phone_verified_normalized' => null,
        ]);

        $bufferedOutput = new \Symfony\Component\Console\Output\BufferedOutput();
        $outputStyle = new \Illuminate\Console\OutputStyle(
            new \Symfony\Component\Console\Input\ArrayInput(['--json' => true]),
            $bufferedOutput
        );
        \Illuminate\Support\Facades\Artisan::call('mova:health-check', ['--json' => true], $outputStyle);
        $decoded = json_decode($bufferedOutput->fetch(), true);

        $adoption = $decoded['info']['whatsapp_adoption'];
        $this->assertStringContainsString('2/3 verificados con opt-in activo', $adoption);
        $this->assertStringContainsString('66.7%', $adoption);
        $this->assertStringContainsString('1 opt-out', $adoption);
        $this->assertStringContainsString('3/4 usuarios con teléfono verificado', $adoption);
    }

    // ── El health-check debe ser defensivo consigo mismo ──────────────────

    public function test_provider_guard_never_instantiates_the_real_provider(): void
    {
        // La preocupación concreta: si el health-check resolviera
        // MetaCloudApiProvider/CulqiPaymentProvider de verdad para validarlos,
        // una credencial rota podría tirar abajo TODO el comando en vez de
        // reportar un único check en rojo. Verificado leyendo ProviderGuard:
        // resolve() es validación de string pura, nunca instancia la clase
        // real — así que ni siquiera puede intentar una llamada HTTP.
        $source = file_get_contents(app_path('Support/ProviderGuard.php'));

        $this->assertStringNotContainsString('new MetaCloudApiProvider', $source);
        $this->assertStringNotContainsString('new CulqiPaymentProvider', $source);
    }

    public function test_a_broken_queue_backlog_query_does_not_take_down_the_other_checks(): void
    {
        // Simula el fallo más catastrófico posible en ESE bloque: la tabla no
        // existe. Los demás checks (settlement, proveedores, timeouts) deben
        // seguir apareciendo en el reporte — el comando no debe reventar
        // entero por un problema localizado en una sola sección.
        $this->breakQueueBacklogQuery();

        $result = $this->artisan('mova:health-check --json')->run();

        $this->assertSame(0, $result, 'Un backlog no consultable no es, por sí solo, una configuración peligrosa.');
    }

    public function test_a_broken_queue_backlog_query_still_reports_other_sections(): void
    {
        $this->breakQueueBacklogQuery();

        // Artisan::call() necesita un OutputStyle real, no un BufferedOutput
        // simple: RefreshDatabase migra la BD llamando a $this->artisan('migrate', ...)
        // en el setUp de este test, y eso deja registrado en el contenedor un
        // OutputStyle::class mockeado (vía PendingCommand::mockConsoleOutput())
        // cuyo BufferedOutput interno ignora doWrite() — Illuminate\Console\Command::run()
        // solo usa el $output que le pasamos TAL CUAL si ya es una instancia de
        // OutputStyle; si le pasamos un BufferedOutput plano, hace
        // `$this->laravel->make(OutputStyle::class, [...])` y el contenedor
        // devuelve ese mock heredado del migrate, silenciando toda la salida.
        // Construir el OutputStyle nosotros mismos evita pasar por el
        // contenedor y por tanto por ese mock contaminado.
        $bufferedOutput = new \Symfony\Component\Console\Output\BufferedOutput();
        $outputStyle = new \Illuminate\Console\OutputStyle(
            new \Symfony\Component\Console\Input\ArrayInput(['--json' => true]),
            $bufferedOutput
        );
        $exitCode = \Illuminate\Support\Facades\Artisan::call('mova:health-check', ['--json' => true], $outputStyle);
        $output = $bufferedOutput->fetch();
        $decoded = json_decode($output, true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded, "La salida no fue JSON válido:\n{$output}");
        $this->assertArrayHasKey('settlement_mode', $decoded['checks']);
        $this->assertArrayHasKey('PAYMENT_PROVIDER', $decoded['checks']);
        $this->assertSame('no disponible', $decoded['checks']['queue_backlog']);
    }

    private function breakQueueBacklogQuery(): void
    {
        $query = \Mockery::mock(\Illuminate\Database\Query\Builder::class);
        $query->shouldReceive('count')->once()->andThrow(new RuntimeException('Queue table unavailable'));
        \Illuminate\Support\Facades\DB::partialMock()
            ->shouldReceive('table')->with('jobs')->once()->andReturn($query);
    }
}
