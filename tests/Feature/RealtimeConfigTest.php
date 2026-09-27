<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AZ-3G — resources/js/echo.js leía import.meta.env.VITE_PUSHER_*, que el
 * build de Docker de producción nunca inyecta (son variables build-time de
 * Vite, no runtime). AppLayout inicializaba Echo incondicionalmente para
 * cualquier usuario autenticado, así que `new Echo({ key: undefined, ... })`
 * fallaba en consola en cada carga autenticada en producción.
 *
 * La config ahora es server-runtime, compartida vía HandleInertiaRequests
 * bajo la prop `realtime` -- nunca 'secret' ni 'app_id'.
 */
class RealtimeConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_realtime_is_disabled_when_broadcast_driver_is_not_pusher(): void
    {
        config([
            'broadcasting.default' => 'null',
            'broadcasting.connections.pusher.key' => 'some-key-that-should-be-ignored',
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('realtime.enabled', false)
                ->where('realtime.key', null)
            );
    }

    public function test_realtime_is_disabled_when_pusher_key_is_missing(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => null,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('realtime.enabled', false)
                ->where('realtime.key', null)
            );
    }

    public function test_realtime_shares_public_config_when_pusher_is_fully_configured(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'app-key-123',
            'broadcasting.connections.pusher.options.cluster' => 'sa1',
            'broadcasting.connections.pusher.options.host' => 'ws-sa1.pusher.com',
            'broadcasting.connections.pusher.options.port' => 443,
            'broadcasting.connections.pusher.options.scheme' => 'https',
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('realtime.enabled', true)
                ->where('realtime.key', 'app-key-123')
                ->where('realtime.cluster', 'sa1')
                ->where('realtime.host', 'ws-sa1.pusher.com')
                ->where('realtime.port', 443)
                ->where('realtime.scheme', 'https')
            );
    }

    public function test_pusher_secret_is_never_present_in_inertia_props(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'app-key-123',
            'broadcasting.connections.pusher.secret' => 'super-secret-value',
            'broadcasting.connections.pusher.app_id' => '999999',
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');
        $response->assertInertia(function ($page) {
            $realtime = $page->toArray()['props']['realtime'];
            $this->assertArrayNotHasKey('secret', $realtime);
            $this->assertArrayNotHasKey('app_id', $realtime);
        });

        // assertDontSee (no assertDontSeeText): el secret viviría dentro del
        // atributo JSON data-page si se filtrara, y assertDontSeeText
        // despoja el HTML antes de comparar -- no miraría dentro de un
        // atributo. assertDontSee compara el contenido crudo de la respuesta.
        $response->assertDontSee('super-secret-value');
        $response->assertDontSee('999999');
    }
}
