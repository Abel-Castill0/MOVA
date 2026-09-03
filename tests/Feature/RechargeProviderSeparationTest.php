<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\PaymentOrder;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\RechargeApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P0 — MOVA Yape Checkout Pre-Card Hardening.
 *
 * Antes de esta ronda, una RechargeRequest payment_method=mercadopago
 * "pendiente" (esperando confirmación server-to-server de Mercado Pago) era
 * indistinguible, para Admin\RechargeController::approve()/reject(), de una
 * recarga manual (yape/transfer) esperando revisión humana. Un admin podía
 * aprobar manualmente ANTES de que el proveedor confirmara el pago, o
 * rechazar una recarga que Mercado Pago aprobaría segundos después —
 * dejándola atascada para siempre (RechargeApprovalService::credit() no
 * reabre un estado terminal). Este archivo cubre exactamente esa frontera:
 * la idempotencia del idempotency_key NUNCA fue la protección aquí — el
 * guard real es "¿quién decide la verdad financiera de esta recarga?".
 */
class RechargeProviderSeparationTest extends TestCase
{
    use RefreshDatabase;

    // ---- provider-managed: admin NUNCA puede decidir manualmente --------

    public function test_admin_cannot_approve_a_pending_mercadopago_recharge(): void
    {
        [, $profile] = $this->teacher();
        $admin = $this->admin();
        $recharge = $this->mercadoPagoRecharge($profile);

        $this->actingAs($admin)
            ->post(route('admin.recharges.approve', $recharge))
            ->assertForbidden();

        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertSame('pending', $recharge->fresh()->status);
        $this->assertDatabaseCount('credit_transactions', 0);
    }

    public function test_admin_cannot_reject_a_pending_mercadopago_recharge(): void
    {
        [, $profile] = $this->teacher();
        $admin = $this->admin();
        $recharge = $this->mercadoPagoRecharge($profile);

        $this->actingAs($admin)
            ->post(route('admin.recharges.reject', $recharge), ['reason' => 'Prueba de frontera provider-managed.'])
            ->assertForbidden();

        $this->assertSame('pending', $recharge->fresh()->status);
    }

    /**
     * El caso concreto que motivó este archivo: Mercado Pago ya RECHAZÓ el
     * pago (el PaymentOrder subyacente quedó 'failed'), pero
     * applyFailed() nunca toca RechargeRequest.status — sigue 'pending' en
     * la lista del admin, indistinguible de una recarga manual esperando
     * revisión. Debe seguir denegado igual: la verdad ya la tiene Mercado
     * Pago, un admin no puede "reabrirla" aprobándola por su cuenta.
     */
    public function test_admin_cannot_approve_a_mercadopago_recharge_whose_underlying_payment_already_failed(): void
    {
        [, $profile] = $this->teacher();
        $admin = $this->admin();
        $recharge = $this->mercadoPagoRecharge($profile);
        PaymentOrder::create([
            'recharge_request_id' => $recharge->id,
            'attempt_number' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'provider' => 'mercadopago',
            'provider_order_id' => '123456',
            'submission_status' => 'submitted',
            'status' => 'failed',
            'amount_minor' => 1000,
            'currency' => 'PEN',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.recharges.approve', $recharge))
            ->assertForbidden();

        $this->assertSame(0, $profile->fresh()->credits_available);
    }

    /**
     * Defensa en profundidad: aunque algo llamara a
     * RechargeApprovalService::credit() con un $reviewerId humano saltándose
     * la Policy/el controller (bug futuro, test directo del servicio), el
     * choke point real sigue denegando.
     */
    public function test_service_layer_denies_manual_credit_even_bypassing_the_http_boundary(): void
    {
        [, $profile] = $this->teacher();
        $recharge = $this->mercadoPagoRecharge($profile);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(RechargeApprovalService::class)->credit($recharge, 999);
    }

    /**
     * PROVIDER CREDIT CHOKE-POINT INVARIANT (MOVA Yape Final Pre-Card Gate):
     * $reviewerId=null por sí solo NO es "Mercado Pago confirmó el pago" —
     * es solo una convención que los productores actuales respetan. Un
     * caller que la invoque sin verdad de proveedor real (sin un
     * PaymentOrder 'paid' vinculado) debe ser rechazado igual que un admin
     * humano, no solo el humano.
     */
    public function test_service_layer_denies_automatic_credit_without_a_confirmed_payment_order(): void
    {
        [, $profile] = $this->teacher();
        $recharge = $this->mercadoPagoRecharge($profile);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        try {
            app(RechargeApprovalService::class)->credit($recharge, null);
        } finally {
            $this->assertSame(0, $profile->fresh()->credits_available);
            $this->assertDatabaseCount('credit_transactions', 0);
        }
    }

    // ---- provider-managed: la reconciliación SÍ puede, exactamente una vez ----

    public function test_mercadopago_reconciliation_credits_exactly_once_even_if_called_twice(): void
    {
        [, $profile] = $this->teacher();
        $recharge = $this->mercadoPagoRecharge($profile);
        $this->paidPaymentOrderFor($recharge);

        $first = app(RechargeApprovalService::class)->credit($recharge, null);
        $second = app(RechargeApprovalService::class)->credit($recharge->fresh(), null);

        $this->assertTrue($first['changed']);
        $this->assertFalse($second['changed']);
        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::where('recharge_request_id', $recharge->id)->where('type', 'deposit')->count());
    }

    // ---- manual: el flujo de siempre sigue intacto ------------------------

    public function test_manual_recharge_admin_approval_still_works(): void
    {
        [, $profile] = $this->teacher();
        $admin = $this->admin();
        $recharge = $this->manualRecharge($profile);

        $this->actingAs($admin)
            ->post(route('admin.recharges.approve', $recharge))
            ->assertSessionHasNoErrors();

        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertSame('approved', $recharge->fresh()->status);
    }

    public function test_manual_recharge_admin_rejection_still_works(): void
    {
        [, $profile] = $this->teacher();
        $admin = $this->admin();
        $recharge = $this->manualRecharge($profile);

        $this->actingAs($admin)
            ->post(route('admin.recharges.reject', $recharge), ['reason' => 'Comprobante ilegible.'])
            ->assertSessionHasNoErrors();

        $this->assertSame('rejected', $recharge->fresh()->status);
        $this->assertSame(0, $profile->fresh()->credits_available);
    }

    // ---- helpers ---------------------------------------------------------

    private function teacher(int $availableCredits = 0): array
    {
        $teacher = User::factory()->create(['password' => 'password']);
        $teacher->assignRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_verified' => true,
            'credits_available' => $availableCredits,
            'credits_reserved' => 0,
        ]);

        return [$teacher, $profile];
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['password' => 'password']);
        $admin->assignRole('admin');

        return $admin;
    }

    private function mercadoPagoRecharge(TeacherProfile $profile): RechargeRequest
    {
        return RechargeRequest::create([
            'teacher_profile_id' => $profile->id,
            'package_code' => 'inicio',
            'package_name' => 'Inicio',
            'credits' => 5,
            'amount_pen' => '10.00',
            'payment_method' => 'mercadopago',
            'operation_number' => 'MP-'.Str::uuid(),
            'operation_number_normalized' => 'MP-'.Str::uuid(),
            'status' => 'pending',
        ]);
    }

    private function paidPaymentOrderFor(RechargeRequest $recharge): PaymentOrder
    {
        return PaymentOrder::create([
            'recharge_request_id' => $recharge->id,
            'attempt_number' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'provider' => 'mercadopago',
            'provider_order_id' => (string) fake()->unique()->numerify('#########'),
            'submission_status' => 'submitted',
            'status' => 'paid',
            'paid_at' => now(),
            'amount_minor' => 1000,
            'currency' => 'PEN',
        ]);
    }

    private function manualRecharge(TeacherProfile $profile): RechargeRequest
    {
        $operation = fake()->unique()->numerify('OP########');

        return RechargeRequest::create([
            'teacher_profile_id' => $profile->id,
            'package_code' => 'inicio',
            'package_name' => 'Inicio',
            'credits' => 5,
            'amount_pen' => '10.00',
            'payment_method' => 'yape',
            'operation_number' => $operation,
            'operation_number_normalized' => $operation,
            'status' => 'pending',
        ]);
    }
}
