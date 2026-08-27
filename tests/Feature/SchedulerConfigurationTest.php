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
 * RefreshDatabase añadido para los tests de resiliencia del health-check
 * (más abajo), que necesitan un DDL destructivo (Schema::drop) de forma
 * segura: sin esto, un DROP TABLE en un test sin transacción propia dejaría
 * la tabla ausente para el resto de la suite en el mismo proceso.
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
        $commands = implode(' | ', $this->scheduledCommands());

        $this->assertStringContainsString('classmate:send-reminders', $commands);
        $this->assertStringContainsString('mova:settle-lessons', $commands);
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
        \Illuminate\Support\Facades\Schema::drop('jobs');

        $result = $this->artisan('mova:health-check --json')->run();

        $this->assertSame(0, $result, 'Un backlog no consultable no es, por sí solo, una configuración peligrosa.');
    }

    public function test_a_broken_queue_backlog_query_still_reports_other_sections(): void
    {
        \Illuminate\Support\Facades\Schema::drop('jobs');

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
}
