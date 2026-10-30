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
use Spatie\Permission\Models\Role;
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

    // ── Panel de administración: ya no se salta nada ─────────────────────────

    private function admin(): User
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_admin_test_goes_only_to_registered_consenting_users_and_through_the_channel(): void
    {
        $user = $this->user();
        $admin = $this->admin();
        // El panel exige MFA/aceptación legal en producción; aquí se prueba la lógica del controlador.
        $this->withoutMiddleware();

        $this->actingAs($admin)->post(route('admin.whatsapp.send-test'), ['phone' => '987654321', 'message' => 'prueba', 'sync' => true])
            ->assertSessionHas('success');
        Http::assertSentCount(1);
        $this->assertSame('waha', WhatsAppMessage::firstOrFail()->provider);

        // Un número que no es de ningún usuario NO se envía.
        $this->actingAs($admin)->post(route('admin.whatsapp.send-test'), ['phone' => '900000000', 'message' => 'x', 'sync' => true])
            ->assertSessionHas('error');
        // Usuario sin consentimiento: tampoco.
        $user->forceFill(['whatsapp_opt_out_at' => now()])->save();
        $this->actingAs($admin)->post(route('admin.whatsapp.send-test'), ['phone' => '987654321', 'message' => 'otra', 'sync' => true])
            ->assertSessionHas('error');

        Http::assertSentCount(1);
    }

    public function test_viewing_the_admin_status_never_logs_out_or_restarts_the_session(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*' => Http::response(['name' => 'default', 'status' => 'FAILED', 'me' => ['id' => '51987654321@c.us']], 200)]);
        $admin = $this->admin();
        $this->withoutMiddleware();

        $this->actingAs($admin)->get(route('admin.whatsapp.status'))->assertOk()->assertJsonPath('status', 'FAILED');

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/logout') || str_contains($r->url(), '/restart'));
    }
}
