<?php

namespace Tests\Feature;

use App\Jobs\WorkerHeartbeatJob;
use App\Support\Heartbeat;
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
            ->assertExactJson(['status' => 'ready', 'checks' => ['database' => 'ok', 'migrations' => 'ok']]);

        $this->assertEmpty($response->headers->getCookies());
    }

    public function test_readiness_fails_with_503_when_runtime_schema_is_missing(): void
    {
        Schema::drop('system_heartbeats');

        $this->get('/readyz')->assertStatus(503)
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('checks.migrations', 'fail');
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
