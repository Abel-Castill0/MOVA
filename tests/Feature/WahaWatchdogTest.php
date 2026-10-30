<?php

namespace Tests\Feature;

use App\Models\OperationalAlert;
use App\WhatsApp\Waha\WahaSessionState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Recuperación por estados: no destructiva, con backoff, sin logout, con alerta a administradores. */
class WahaWatchdogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['services.waha.admin_control_enabled' => true, 'services.waha.url' => 'http://127.0.0.1:3000', 'services.waha.api_key' => 'k']);
    }

    private function waha(?string $status): void
    {
        Http::swap(new Factory());
        Http::fake(fn (Request $r) => $r->method() === 'GET' ? ($status === null ? throw new ConnectionException('down') : Http::response(['status' => $status], 200)) : Http::response([], 201));
    }

    private function posts(string $suffix): int
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), $suffix))->count();
    }

    public function test_it_does_nothing_when_admin_control_is_disabled(): void
    {
        config(['services.waha.admin_control_enabled' => false]);
        $this->waha('FAILED');

        $this->artisan('mova:waha-watchdog')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_working_session_is_never_touched_and_alerts_are_resolved(): void
    {
        $this->waha('WORKING');

        $this->artisan('mova:waha-watchdog')->assertSuccessful();

        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    }

    public function test_a_stopped_session_is_started_with_the_official_endpoint_and_respects_the_backoff(): void
    {
        $this->waha('STOPPED');

        $this->artisan('mova:waha-watchdog');
        $this->artisan('mova:waha-watchdog'); // dentro del backoff: sin segundo intento

        $this->assertSame(1, $this->posts('/start'));
        $this->assertSame(0, $this->posts('/logout'));
    }

    public function test_a_failed_session_after_being_connected_alerts_admins_and_only_restarts_never_logs_out(): void
    {
        WahaSessionState::record('WORKING');
        $this->waha('FAILED');

        $this->artisan('mova:waha-watchdog');

        $this->assertDatabaseHas('operational_alerts', ['alert_key' => 'waha:link_lost', 'severity' => OperationalAlert::SEVERITY_CRITICAL]);
        $this->assertSame(1, $this->posts('/restart'));
        $this->assertSame(0, $this->posts('/logout'));
        $this->assertSame(0, $this->posts('/start'));
    }

    public function test_recovery_attempts_stop_after_the_backoff_schedule_is_exhausted(): void
    {
        $this->waha('FAILED');

        for ($i = 0; $i < 6; $i++) {
            $this->artisan('mova:waha-watchdog');
            $this->travel(13)->hours(); // supera cada espera
        }

        $this->assertSame(4, $this->posts('/restart'));
    }

    public function test_an_unreachable_server_raises_an_alert_only_after_repeated_failures_and_touches_nothing(): void
    {
        $this->waha(null);

        $this->artisan('mova:waha-watchdog');
        $this->artisan('mova:waha-watchdog');
        $this->assertDatabaseMissing('operational_alerts', ['alert_key' => 'waha:unavailable']);

        $this->artisan('mova:waha-watchdog');
        $this->assertDatabaseHas('operational_alerts', ['alert_key' => 'waha:unavailable']);
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    }
}
