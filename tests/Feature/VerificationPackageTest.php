<?php

namespace Tests\Feature;

use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Support\CreditPackages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Paquete de VERIFICACIÓN de cobro (1 sol): solo para la cuenta del propietario, con checkout restringido.
 * Fail closed: apagado por defecto, nunca con allowlist vacía (apertura pública) ni para otros usuarios.
 */
class VerificationPackageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('teacher', 'web');

        config([
            'payments.enabled' => true,
            'payments.provider' => 'mercadopago',
            'payments.mercadopago.base_url' => 'https://api.mercadopago.com',
            'payments.mercadopago.access_token' => 'checkout-test-placeholder-000',
            'payments.mercadopago.public_key' => 'TEST-lab-public-key',
            'payments.mercadopago.application_id' => '6583217782927097',
            'payments.mercadopago.expected_collector_id' => '123456789',
            'payments.mercadopago.expected_live_mode' => 'false',
            'payments.mercadopago.webhooks_enabled' => false,
        ]);
    }

    public function test_package_is_not_offered_by_default(): void
    {
        [$owner] = $this->teacher('owner@example.test');
        config(['payments.mercadopago.checkout_allowlist' => 'owner@example.test']);

        $this->assertArrayNotHasKey('verificacion', CreditPackages::forCheckout($owner));
    }

    public function test_package_is_offered_only_to_allowlisted_user_when_enabled(): void
    {
        config([
            'credits.verification_package.enabled' => true,
            'payments.mercadopago.checkout_allowlist' => 'owner@example.test',
        ]);
        [$owner] = $this->teacher('owner@example.test');
        [$other] = $this->teacher('other@example.test');

        $this->assertSame('1.00', CreditPackages::forCheckout($owner)['verificacion']['amount_pen']);
        $this->assertSame(1, CreditPackages::forCheckout($owner)['verificacion']['credits']);
        $this->assertArrayNotHasKey('verificacion', CreditPackages::forCheckout($other));
        $this->assertArrayNotHasKey('verificacion', CreditPackages::forCheckout(null));
        // El catálogo normal siempre se conserva.
        $this->assertArrayHasKey('inicio', CreditPackages::forCheckout($owner));
    }

    public function test_package_is_never_offered_with_an_open_checkout(): void
    {
        config([
            'credits.verification_package.enabled' => true,
            'payments.mercadopago.checkout_allowlist' => '',
        ]);
        [$user] = $this->teacher('anyone@example.test');

        $this->assertArrayNotHasKey('verificacion', CreditPackages::forCheckout($user));

        $this->actingAs($user)->post(route('teacher.credits.checkout.store'), ['package_code' => 'verificacion'])
            ->assertSessionHasErrors('package_code');
        $this->assertSame(0, RechargeRequest::count());
    }

    public function test_owner_can_start_a_verification_checkout_and_amount_comes_from_the_server(): void
    {
        config([
            'credits.verification_package.enabled' => true,
            'payments.mercadopago.checkout_allowlist' => 'owner@example.test',
        ]);
        [$owner, $profile] = $this->teacher('owner@example.test');

        $this->actingAs($owner)->post(route('teacher.credits.checkout.store'), [
            'package_code' => 'verificacion',
            'amount_pen' => '0.01',
            'credits' => 9999,
        ])->assertRedirect();

        $recharge = RechargeRequest::where('teacher_profile_id', $profile->id)->sole();
        $this->assertSame('verificacion', $recharge->package_code);
        $this->assertSame('1.00', $recharge->amount_pen);
        $this->assertSame(1, $recharge->credits);
        $this->assertSame('pending', $recharge->status);
    }

    public function test_non_allowlisted_teacher_cannot_start_it_even_when_enabled(): void
    {
        config([
            'credits.verification_package.enabled' => true,
            'payments.mercadopago.checkout_allowlist' => 'owner@example.test',
        ]);
        [$other] = $this->teacher('other@example.test');

        // Con la allowlist activa y el usuario fuera de ella el checkout completo no está disponible (503).
        $this->actingAs($other)->post(route('teacher.credits.checkout.store'), ['package_code' => 'verificacion'])
            ->assertStatus(503);
        $this->assertSame(0, RechargeRequest::count());
    }

    private function teacher(string $email): array
    {
        $user = User::factory()->create(['email' => $email, 'password' => 'password']);
        $user->assignRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $user->id,
            'is_verified' => true,
            'credits_available' => 0,
            'credits_reserved' => 0,
        ]);

        return [$user, $profile];
    }
}
