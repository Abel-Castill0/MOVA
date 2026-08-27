<?php

namespace Tests\Feature;

use App\Models\User;
use App\WhatsApp\Contracts\WhatsAppProviderContract;
use App\WhatsApp\FakeWhatsAppProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cubre la migración de PhoneVerificationController::sendWhatsAppCode() de
 * un cliente Twilio instanciado directamente al mismo
 * WhatsAppProviderContract que usa WhatsAppChannel (ver
 * docs/whatsapp-architecture.md). El código de un solo uso va a su propia
 * plantilla ('phone_verification_code'), separada de la genérica de
 * notificaciones.
 */
class PhoneVerificationWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('parent', 'web');
        config(['services.whatsapp.enabled' => true]);
    }

    public function test_send_calls_the_provider_with_the_otp_template_and_the_code(): void
    {
        $user = User::factory()->create(['phone' => '+51987654321', 'phone_verified_at' => null]);
        $user->assignRole('parent');

        $this->actingAs($user)->post(route('phone.verification.send'));

        $provider = app(WhatsAppProviderContract::class);
        $this->assertInstanceOf(FakeWhatsAppProvider::class, $provider);
        $this->assertCount(1, $provider->sent);
        $this->assertSame('+51987654321', $provider->sent[0]['to']);
        $this->assertSame('phone_verification_code', $provider->sent[0]['template']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $provider->sent[0]['params'][0]);
    }

    public function test_send_does_not_call_the_provider_when_whatsapp_disabled(): void
    {
        config(['services.whatsapp.enabled' => false]);
        $user = User::factory()->create(['phone' => '+51987654321', 'phone_verified_at' => null]);
        $user->assignRole('parent');

        $this->actingAs($user)->post(route('phone.verification.send'));

        $this->assertCount(0, app(WhatsAppProviderContract::class)->sent);
    }

    public function test_send_passes_a_client_reference_without_the_code_in_it(): void
    {
        $user = User::factory()->create(['phone' => '+51987654321', 'phone_verified_at' => null]);
        $user->assignRole('parent');

        $this->actingAs($user)->post(route('phone.verification.send'));

        $provider = app(WhatsAppProviderContract::class);
        $this->assertSame("phone_verification:{$user->id}", $provider->sent[0]['client_reference']);
    }

    // ── Ciclo de vida del OTP: consumo, reemplazo, no reutilización ─────────

    public function test_code_is_consumed_and_cannot_be_reused_after_successful_verification(): void
    {
        $user = User::factory()->create(['phone' => '+51987654321', 'phone_verified_at' => null]);
        $user->assignRole('parent');

        $send = $this->actingAs($user)->post(route('phone.verification.send'));
        $code = $send->getSession()->get('debugCode');

        $this->actingAs($user)->post(route('phone.verification.verify'), ['code' => $code])
            ->assertSessionDoesntHaveErrors();
        $this->assertNotNull($user->fresh()->phone_verified_at);

        // Revertir la verificación para poder reintentar el MISMO código ya
        // consumido — si el código siguiera siendo válido, esto pasaría.
        $user->forceFill(['phone_verified_at' => null, 'phone_verified_normalized' => null])->save();

        $this->actingAs($user)->post(route('phone.verification.verify'), ['code' => $code])
            ->assertSessionHasErrors('code');
    }

    public function test_requesting_a_new_code_invalidates_the_previous_one(): void
    {
        $user = User::factory()->create(['phone' => '+51987654321', 'phone_verified_at' => null]);
        $user->assignRole('parent');

        $first = $this->actingAs($user)->post(route('phone.verification.send'));
        $firstCode = $first->getSession()->get('debugCode');

        $second = $this->actingAs($user)->post(route('phone.verification.send'));
        $secondCode = $second->getSession()->get('debugCode');

        $this->assertNotSame($firstCode, $secondCode, 'la prueba necesita dos códigos distintos para ser significativa');

        $this->actingAs($user)->post(route('phone.verification.verify'), ['code' => $firstCode])
            ->assertSessionHasErrors('code');
        $this->assertNull($user->fresh()->phone_verified_at);
    }

    // ── Rate limiting por número (cruza cuentas, no solo por usuario) ───────

    public function test_rate_limits_otp_sends_per_phone_number_across_different_accounts(): void
    {
        $phone = '+51987654321';

        for ($i = 0; $i < 5; $i++) {
            $user = User::factory()->create(['phone' => $phone, 'phone_verified_at' => null]);
            $user->assignRole('parent');
            $this->actingAs($user)->post(route('phone.verification.send'))
                ->assertSessionDoesntHaveErrors();
        }

        // El 6º intento, con una cuenta DISTINTA pero el MISMO número, debe
        // bloquearse — el throttle de la ruta es por usuario/IP y no lo
        // detendría por sí solo.
        $sixthUser = User::factory()->create(['phone' => $phone, 'phone_verified_at' => null]);
        $sixthUser->assignRole('parent');

        $this->actingAs($sixthUser)->post(route('phone.verification.send'))
            ->assertSessionHasErrors('phone');
    }
}
