<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WahaCheckCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://10.30.0.4:3000', 'services.waha.api_key' => 'secret-key-123', 'services.waha.session' => 'default']);
    }

    public function test_reports_reachable_and_the_session_status_without_leaking_the_key(): void
    {
        Http::fake(['*' => Http::response(['name' => 'default', 'status' => 'WORKING'], 200)]);

        $this->artisan('mova:waha-check')
            ->expectsOutputToContain('Alcanzable. Estado de la sesión: WORKING')
            ->doesntExpectOutputToContain('secret-key-123')
            ->assertSuccessful();

        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_ends_with($r->url(), '/api/sessions/default'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/restart') || str_contains($r->url(), '/logout'));
    }

    public function test_fails_when_waha_is_not_reachable(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        $this->artisan('mova:waha-check')->expectsOutputToContain('No alcanzable')->assertFailed();
    }
}
