<?php

namespace Tests\Feature;

use App\Jobs\WorkerHeartbeatJob;
use App\Models\OperationalAlert;
use App\Models\User;
use App\Support\Heartbeat;
use Spatie\Permission\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HealthAndHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_liveness_is_ok_and_sets_no_session_cookie(): void
    {
        $response = $this->get('/healthz')->assertOk()->assertSee('OK');

        $this->assertEmpty($response->headers->getCookies(), '/healthz no debe emitir cookies de sesión.');
    }

    public function test_readiness_reports_ok_without_leaking_details(): void
    {
        $response = $this->get('/readyz')->assertOk()
            ->assertExactJson(['status' => 'ready', 'checks' => ['database' => 'ok', 'migrations' => 'ok', 'cache' => 'ok']]);

        $this->assertEmpty($response->headers->getCookies());
    }

    public function test_readiness_fails_with_503_when_a_migration_is_pending(): void
    {
        // Código nuevo sobre esquema viejo (sin DDL: se quita el registro de la migración).
        DB::table('migrations')->where('migration', '2026_09_23_000002_create_complaint_sequences_table')->delete();

        $this->get('/readyz')->assertStatus(503)
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('checks.migrations', 'fail');
    }

    public function test_readiness_fails_with_503_when_the_cache_store_is_unusable(): void
    {
        config(['cache.stores.broken' => ['driver' => 'database', 'table' => 'no_such_cache_table', 'connection' => null]]);
        config(['cache.default' => 'broken']);

        $this->get('/readyz')->assertStatus(503)->assertJsonPath('checks.cache', 'fail');
    }

    // /readyz es readiness del WEB: worker/scheduler caídos no lo sacan del balanceador...
    public function test_missing_heartbeats_do_not_make_web_unready(): void
    {
        $this->assertSame('unknown', Heartbeat::status(Heartbeat::SCHEDULER));
        $this->get('/readyz')->assertOk();
    }

    // ...pero nunca en silencio: health-check (alerta a admins) y Operations lo marcan.
    public function test_missing_or_stale_heartbeats_raise_production_signals(): void
    {
        $this->app['env'] = 'production';
        Heartbeat::beat(Heartbeat::WORKER);
        DB::table('system_heartbeats')->where('name', Heartbeat::WORKER)
            ->update(['beat_at' => now()->subSeconds(Heartbeat::STALE_AFTER_SECONDS + 60)]);
        // scheduler: nunca latió.

        $this->artisan('mova:health-check')
            ->expectsOutputToContain('WORKER_HEARTBEAT_STALE')
            ->expectsOutputToContain('SCHEDULER_HEARTBEAT_STALE')
            ->assertFailed();

        $this->artisan('mova:health-check', ['--alert' => true]);
        $this->assertTrue(OperationalAlert::query()->open()->where('alert_key', 'health:SCHEDULER_HEARTBEAT_STALE')->exists());
        $this->assertTrue(OperationalAlert::query()->open()->where('alert_key', 'health:WORKER_HEARTBEAT_STALE')->exists());

        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $caps = collect($this->actingAs($admin)->get(route('admin.operations'))->assertOk()->inertiaPage()['props']['capabilities'])
            ->keyBy('key');
        $this->assertSame('attention', $caps['worker']['status']);
        $this->assertSame('attention', $caps['scheduler']['status']);
    }

    public function test_scheduler_heartbeat_event_beats_and_enqueues_worker_heartbeat(): void
    {
        Queue::fake();

        $this->artisan('schedule:test', ['--name' => 'mova:heartbeat'])->assertSuccessful();

        $this->assertSame('healthy', Heartbeat::status(Heartbeat::SCHEDULER));
        Queue::assertPushed(WorkerHeartbeatJob::class);
    }

    public function test_health_check_flags_missing_jaas_in_production(): void
    {
        config(['jaas.app_id' => null]);
        $this->app['env'] = 'production';

        $this->artisan('mova:health-check')->expectsOutputToContain('JAAS_NOT_CONFIGURED');
    }

    public function test_worker_heartbeat_job_and_staleness(): void
    {
        $this->assertSame('unknown', Heartbeat::status(Heartbeat::WORKER));

        (new WorkerHeartbeatJob())->handle();
        $this->assertSame('healthy', Heartbeat::status(Heartbeat::WORKER));

        DB::table('system_heartbeats')->where('name', Heartbeat::WORKER)
            ->update(['beat_at' => now()->subSeconds(Heartbeat::STALE_AFTER_SECONDS + 60)]);
        $this->assertSame('stale', Heartbeat::status(Heartbeat::WORKER));
    }
}
