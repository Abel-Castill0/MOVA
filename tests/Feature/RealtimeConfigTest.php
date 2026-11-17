<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La configuración de tiempo real viaja al navegador como una prop de Inertia (`realtime`): solo dice si hay tiempo
 * real y dónde negociar. Nunca la clave del servicio ni su endpoint (AZ-3G: el build de producción no inyecta variables
 * de Vite; todo es de servidor). Desde la migración a Azure SignalR el único interruptor es REALTIME_DRIVER; la antigua
 * ruta Pusher/Echo ya no activa nada.
 */
class RealtimeConfigTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'clave-de-prueba-signalr-0123456789abcdef';

    private function realtimeProp(): array
    {
        return $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk()->inertiaPage()['props']['realtime'];
    }

    public function test_realtime_is_disabled_by_default(): void
    {
        $this->assertSame(['enabled' => false, 'driver' => null, 'negotiateUrl' => null], $this->realtimeProp());
    }

    public function test_a_leftover_pusher_configuration_does_not_enable_realtime(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'app-key-123',
        ]);

        $this->assertFalse($this->realtimeProp()['enabled']);
    }

    public function test_signalr_without_endpoint_or_key_stays_disabled(): void
    {
        config(['realtime.driver' => 'signalr', 'realtime.signalr.endpoint' => null, 'realtime.signalr.access_key' => self::KEY]);
        $this->assertFalse($this->realtimeProp()['enabled']);

        config(['realtime.signalr.endpoint' => 'https://mova-test.service.signalr.net', 'realtime.signalr.access_key' => 'corta']);
        $this->assertFalse($this->realtimeProp()['enabled']);
    }

    public function test_signalr_shares_only_the_negotiate_route_and_never_the_key_or_endpoint(): void
    {
        config(['realtime.driver' => 'signalr', 'realtime.signalr.endpoint' => 'https://mova-test.service.signalr.net', 'realtime.signalr.access_key' => self::KEY]);

        $response = $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk();

        $this->assertSame(['enabled' => true, 'driver' => 'signalr', 'negotiateUrl' => '/realtime/negotiate'], $response->inertiaPage()['props']['realtime']);
        // assertDontSee compara el contenido crudo: la clave viviría dentro del atributo JSON data-page si se filtrara.
        $response->assertDontSee(self::KEY);
        $response->assertDontSee('mova-test.service.signalr.net');
    }
}
