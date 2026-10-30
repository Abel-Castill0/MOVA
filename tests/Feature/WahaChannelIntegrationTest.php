<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Notifications\WhatsAppTestNotification;
use App\WhatsApp\Contracts\WhatsAppProviderContract;
use App\WhatsApp\Waha\WahaSessionState;
use App\WhatsApp\WahaProvider;
use App\WhatsApp\WhatsAppMessageStatus;
use App\WhatsApp\WhatsAppSkipReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Tests\TestCase;

/**
 * WAHA como proveedor DE WhatsAppChannel: el canal sigue siendo la única puerta y ninguna verificación se salta
 * (consentimiento, baja, teléfono verificado, suspensión, cuenta cerrada) ni por el canal ni por el panel de administración.
 */
class WahaChannelIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        RateLimiter::clear('waha:global');
        config([
            'services.whatsapp.enabled' => true,
            'services.whatsapp.provider' => 'waha',
            'services.waha.url' => 'http://127.0.0.1:3000',
            'services.waha.api_key' => 'k',
            'services.waha.hmac_key' => 'h',
            'services.waha.typing_min_ms' => 0,
            'services.waha.typing_max_ms' => 0,
            'services.waha.rate_wait_seconds' => 0,
        ]);
        WahaSessionState::record('WORKING');
        Http::fake(['*' => Http::response(['id' => 'true_51987654321@c.us_3EB0ABCDEF01'], 201)]);
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'phone' => '987654321',
            'phone_verified_at' => now(),
            'phone_verified_normalized' => '+51987654321',
            'whatsapp_opt_in_at' => now(),
            'whatsapp_opt_out_at' => null,
        ], $attributes));
    }

    public function test_the_provider_binding_resolves_waha(): void
    {
        $this->assertInstanceOf(WahaProvider::class, app(WhatsAppProviderContract::class));
    }

    public function test_enabled_waha_without_credentials_fails_closed(): void
    {
        config(['services.waha.hmac_key' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WAHA_HOOK_HMAC_KEY');

        app(WhatsAppProviderContract::class);
    }

    public function test_a_consenting_verified_user_receives_the_message_through_the_channel(): void
    {
        $user = $this->user();

        $user->notifyNow(new WhatsAppTestNotification('hola'));

        Http::assertSentCount(1);
        $row = WhatsAppMessage::firstOrFail();
        $this->assertSame(WhatsAppMessageStatus::Sent, $row->status);
        $this->assertSame('waha', $row->provider);
        $this->assertStringStartsWith('WhatsAppTestNotification#'.$user->id, (string) $row->client_reference);
    }

    public function test_no_consent_means_no_message(): void
    {
        $neverOptedIn = $this->user(['whatsapp_opt_in_at' => null]);
        $neverOptedIn->notifyNow(new WhatsAppTestNotification('hola'));

        $optedOut = $this->user(['phone' => '911111111', 'phone_verified_normalized' => '+51911111111', 'whatsapp_opt_out_at' => now()]);
        $optedOut->notifyNow(new WhatsAppTestNotification('hola'));

        Http::assertNothingSent();
        $this->assertSame(2, WhatsAppMessage::where('skip_reason', WhatsAppSkipReason::OptOut)->count());
    }

    public function test_suspended_and_closed_accounts_get_nothing(): void
    {
        // suspended_at / account_closed_at no son asignables en masa: se fijan con forceFill, como hace el cierre de cuenta.
        $suspended = $this->user();
        $suspended->forceFill(['suspended_at' => now()])->save();
        $closed = $this->user(['phone' => '922222222', 'phone_verified_normalized' => '+51922222222']);
        $closed->forceFill(['account_closed_at' => now()])->save();

        $suspended->notifyNow(new WhatsAppTestNotification('hola'));
        // Un listener global (PreventClosedAccountNotifications) ya frena las cuentas cerradas ANTES del canal; aquí se
        // prueba el canal directamente para demostrar la defensa en profundidad si alguna vez se invocara sin ese listener.
        (new \App\Channels\WhatsAppChannel())->send($closed, new WhatsAppTestNotification('hola'));
        $closed->notifyNow(new WhatsAppTestNotification('hola'));

        Http::assertNothingSent();
        $this->assertSame(1, WhatsAppMessage::where('skip_reason', WhatsAppSkipReason::Suspended)->count());
        $this->assertSame(1, WhatsAppMessage::where('skip_reason', WhatsAppSkipReason::AccountClosed)->count());
    }

    public function test_unverified_phone_gets_nothing(): void
    {
        $this->user(['phone_verified_at' => null])->notifyNow(new WhatsAppTestNotification('hola'));

        Http::assertNothingSent();
    }

    public function test_the_global_switch_off_sends_nothing(): void
    {
        config(['services.whatsapp.enabled' => false, 'services.whatsapp.provider' => 'fake']);

        $this->user()->notifyNow(new WhatsAppTestNotification('hola'));

        Http::assertNothingSent();
        $this->assertDatabaseCount('whatsapp_messages', 0);
    }
}
