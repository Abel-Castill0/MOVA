<?php

namespace Tests\Feature;

use App\Models\LegalAcceptance;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Support\CheckoutAllowlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Staging puede tener el checkout de Mercado Pago encendido en sandbox sin abrirlo a
 * todas las cuentas: MERCADOPAGO_CHECKOUT_ALLOWLIST lo acota a los correos QA.
 */
class CheckoutAllowlistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('teacher', 'web');
        config([
            'payments.enabled' => true,
            'payments.provider' => 'mercadopago',
            'payments.mercadopago.public_key' => 'TEST-public-key-for-tests',
            'payments.mercadopago.checkout_allowlist' => 'qa-teacher@example.com',
        ]);
    }

    /** @return array{0: User, 1: TeacherProfile} */
    private function teacher(string $email): array
    {
        $user = User::factory()->create(['email' => $email, 'password' => 'password']);
        $user->assignRole('teacher');
        LegalAcceptance::recordMissingCurrent($user, null);
        $profile = TeacherProfile::create(['user_id' => $user->id, 'is_verified' => true, 'credits_available' => 0, 'credits_reserved' => 0]);

        return [$user, $profile];
    }

    public function test_an_allowlisted_teacher_can_open_a_checkout(): void
    {
        [$qa] = $this->teacher('qa-teacher@example.com');

        $this->actingAs($qa)->post(route('teacher.credits.checkout.store'), ['package_code' => 'inicio'])
            ->assertRedirect();
        $this->assertSame(1, RechargeRequest::where('payment_method', 'mercadopago')->count());
    }

    public function test_any_other_teacher_cannot_create_a_checkout_nor_pay(): void
    {
        [$other, $profile] = $this->teacher('otra-persona@example.com');

        $this->actingAs($other)->post(route('teacher.credits.checkout.store'), ['package_code' => 'inicio'])
            ->assertStatus(503);
        $this->assertSame(0, RechargeRequest::count());

        // Aunque tuviera una recarga previa, no puede pagarla mientras la lista esté activa.
        $recharge = RechargeRequest::create([
            'teacher_profile_id' => $profile->id, 'package_code' => 'inicio', 'package_name' => 'Inicio',
            'credits' => 5, 'amount_pen' => '10.00', 'payment_method' => 'mercadopago', 'status' => 'pending',
        ]);
        $this->actingAs($other)->postJson(route('teacher.credits.checkout.pay', $recharge), ['payment_method' => 'yape', 'token' => 'tok'])
            ->assertStatus(503);
    }

    public function test_the_credits_page_only_offers_the_automatic_checkout_to_allowlisted_users(): void
    {
        [$qa] = $this->teacher('qa-teacher@example.com');
        [$other] = $this->teacher('otra-persona@example.com');

        $this->actingAs($qa)->get(route('teacher.credits.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('mercadoPagoCheckoutEnabled', true));
        $this->actingAs($other)->get(route('teacher.credits.index'))
            ->assertInertia(fn ($page) => $page->where('mercadoPagoCheckoutEnabled', false));
    }

    public function test_an_empty_allowlist_means_no_restriction(): void
    {
        config(['payments.mercadopago.checkout_allowlist' => null]);
        [$other] = $this->teacher('otra-persona@example.com');

        $this->assertFalse(CheckoutAllowlist::isActive());
        $this->assertTrue(CheckoutAllowlist::permits($other));
        $this->actingAs($other)->post(route('teacher.credits.checkout.store'), ['package_code' => 'inicio'])->assertRedirect();
    }

    public function test_matching_is_exact_and_case_insensitive_and_fails_closed_without_a_user(): void
    {
        config(['payments.mercadopago.checkout_allowlist' => ' QA-Teacher@Example.com , otro@example.com ']);

        $this->assertTrue(CheckoutAllowlist::permits(new User(['email' => 'qa-teacher@example.com'])));
        $this->assertFalse(CheckoutAllowlist::permits(new User(['email' => 'qa-teacher@example.com.evil.com'])));
        $this->assertFalse(CheckoutAllowlist::permits(new User(['email' => 'xqa-teacher@example.com'])));
        $this->assertFalse(CheckoutAllowlist::permits(null));
    }
}
