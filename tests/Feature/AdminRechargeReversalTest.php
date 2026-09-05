<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\OperationalAlert;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Notifications\RechargeReversedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * H-02 — Camino de producto para revertir una recarga aprobada.
 *
 * El servicio (RechargeApprovalService::reverse()) existía y estaba probado,
 * pero NINGUNA ruta lo alcanzaba: un Yape falso aprobado a mano no se podía
 * deshacer desde MOVA (docs/MOVA_SYSTEM_MAP.md H-02, R-02).
 *
 * Estos tests cubren el LÍMITE HTTP y la autorización, que es lo que faltaba —
 * la mecánica financiera ya la cubre PaymentOrderTest.
 */
class AdminRechargeReversalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role);
        }
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    /**
     * @return array{0: User, 1: TeacherProfile, 2: RechargeRequest}
     */
    private function approvedRecharge(int $credits = 15, string $method = 'yape', int $availableAfter = 15): array
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');

        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'hourly_rate' => 20,
            'credits_available' => $availableAfter,
            'is_verified' => true,
        ]);

        $recharge = RechargeRequest::create([
            'teacher_profile_id' => $profile->id,
            'package_code' => 'impulso',
            'package_name' => 'Impulso',
            'credits' => $credits,
            'amount_pen' => '30.00',
            'payment_method' => $method,
            'operation_number' => '000123456',
            'operation_number_normalized' => '000123456',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        CreditTransaction::create([
            'teacher_profile_id' => $profile->id,
            'recharge_request_id' => $recharge->id,
            'idempotency_key' => "recharge:{$recharge->id}:deposit",
            'type' => 'deposit',
            'amount' => $credits,
            'description' => 'Recarga de paquete: Impulso',
        ]);

        return [$teacher, $profile, $recharge];
    }

    // ── Camino feliz ─────────────────────────────────────────────────────

    public function test_an_admin_can_reverse_an_approved_manual_recharge(): void
    {
        Notification::fake();
        [$teacher, $profile, $recharge] = $this->approvedRecharge();

        $this->actingAs($this->admin())
            ->post(route('admin.recharges.reverse', $recharge), [
                'reason' => 'El número de operación no corresponde a ningún abono recibido.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('reversed', $recharge->fresh()->status);
        $this->assertSame(0, $profile->fresh()->credits_available);

        // Ledger append-only: el depósito original NO se toca; se añade el
        // asiento inverso.
        $this->assertDatabaseHas('credit_transactions', [
            'idempotency_key' => "recharge:{$recharge->id}:deposit",
            'amount' => 15,
        ]);
        $this->assertDatabaseHas('credit_transactions', [
            'idempotency_key' => "recharge:{$recharge->id}:reversal",
            'type' => 'reversal',
            'amount' => -15,
        ]);

        Notification::assertSentTo($teacher, RechargeReversedNotification::class);
    }

    public function test_the_reversal_records_who_did_it_and_why(): void
    {
        Notification::fake();
        $admin = $this->admin();
        [, , $recharge] = $this->approvedRecharge();

        $this->actingAs($admin)->post(route('admin.recharges.reverse', $recharge), [
            'reason' => 'Comprobante duplicado detectado en la conciliación bancaria.',
        ]);

        $reversed = $recharge->fresh();
        $this->assertSame($admin->id, $reversed->reversed_by);
        $this->assertNotNull($reversed->reversed_at);
        $this->assertStringContainsString('Comprobante duplicado', $reversed->reversal_reason);
    }

    // ── Autorización ─────────────────────────────────────────────────────

    public function test_a_teacher_can_never_reverse_a_recharge_not_even_their_own(): void
    {
        Notification::fake();
        [$teacher, $profile, $recharge] = $this->approvedRecharge();

        $this->actingAs($teacher)
            ->post(route('admin.recharges.reverse', $recharge), ['reason' => 'Quiero mis créditos de vuelta.'])
            ->assertForbidden();

        $this->assertSame('approved', $recharge->fresh()->status);
        $this->assertSame(15, $profile->fresh()->credits_available);
    }

    public function test_a_parent_cannot_reverse_a_recharge(): void
    {
        Notification::fake();
        $parent = User::factory()->create();
        $parent->assignRole('parent');
        [, , $recharge] = $this->approvedRecharge();

        $this->actingAs($parent)
            ->post(route('admin.recharges.reverse', $recharge), ['reason' => 'Motivo cualquiera aquí.'])
            ->assertForbidden();

        $this->assertSame('approved', $recharge->fresh()->status);
    }

    public function test_a_guest_cannot_reverse_a_recharge(): void
    {
        [, , $recharge] = $this->approvedRecharge();

        $this->post(route('admin.recharges.reverse', $recharge), ['reason' => 'Motivo cualquiera aquí.'])
            ->assertRedirect(route('login'));
    }

    /**
     * Mismo criterio que approve()/reject(): la verdad de una recarga de
     * Mercado Pago la decide el proveedor. Revertirla a mano descontaría
     * créditos sin que el dinero haya vuelto.
     */
    public function test_an_admin_cannot_manually_reverse_a_mercadopago_recharge(): void
    {
        Notification::fake();
        [, $profile, $recharge] = $this->approvedRecharge(method: 'mercadopago');

        $this->actingAs($this->admin())
            ->post(route('admin.recharges.reverse', $recharge), [
                'reason' => 'Quiero revertir esto manualmente sin pasar por el proveedor.',
            ])
            ->assertForbidden();

        $this->assertSame('approved', $recharge->fresh()->status);
        $this->assertSame(15, $profile->fresh()->credits_available);
    }

    // ── Contrato de entrada ──────────────────────────────────────────────

    public function test_the_reason_is_mandatory(): void
    {
        Notification::fake();
        [, , $recharge] = $this->approvedRecharge();

        $this->actingAs($this->admin())
            ->post(route('admin.recharges.reverse', $recharge), [])
            ->assertSessionHasErrors('reason');

        $this->assertSame('approved', $recharge->fresh()->status);
    }

    public function test_a_too_short_reason_is_rejected(): void
    {
        Notification::fake();
        [, , $recharge] = $this->approvedRecharge();

        $this->actingAs($this->admin())
            ->post(route('admin.recharges.reverse', $recharge), ['reason' => 'error'])
            ->assertSessionHasErrors('reason');

        $this->assertSame('approved', $recharge->fresh()->status);
    }

    // ── Estados imposibles e idempotencia ────────────────────────────────

    public function test_a_pending_recharge_cannot_be_reversed(): void
    {
        Notification::fake();
        [, , $recharge] = $this->approvedRecharge();
        $recharge->update(['status' => 'pending', 'approved_at' => null]);

        $this->actingAs($this->admin())
            ->post(route('admin.recharges.reverse', $recharge), [
                'reason' => 'Intento revertir algo que nunca se abonó.',
            ])
            ->assertStatus(422);

        $this->assertSame('pending', $recharge->fresh()->status);
    }

    public function test_a_rejected_recharge_cannot_be_reversed(): void
    {
        Notification::fake();
        [, , $recharge] = $this->approvedRecharge();
        $recharge->update(['status' => 'rejected']);

        $this->actingAs($this->admin())
            ->post(route('admin.recharges.reverse', $recharge), [
                'reason' => 'Intento revertir una recarga ya rechazada.',
            ])
            ->assertStatus(422);
    }

    /**
     * Doble clic, doble envío o reintento: el segundo no debe volver a
     * descontar créditos ni volver a notificar.
     */
    public function test_reversing_twice_is_idempotent(): void
    {
        Notification::fake();
        [$teacher, $profile, $recharge] = $this->approvedRecharge();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.recharges.reverse', $recharge), [
            'reason' => 'Primer intento de reversión legítimo.',
        ]);
        $this->actingAs($admin)->post(route('admin.recharges.reverse', $recharge), [
            'reason' => 'Segundo intento, no debe descontar de nuevo.',
        ])->assertSessionHas('success');

        $this->assertSame(0, $profile->fresh()->credits_available, 'No debe descontar dos veces.');
        $this->assertSame(
            1,
            CreditTransaction::where('recharge_request_id', $recharge->id)->where('type', 'reversal')->count()
        );

        Notification::assertSentToTimes($teacher, RechargeReversedNotification::class, 1);
    }

    // ── Saldo negativo: contrato real, no invención ──────────────────────

    /**
     * §7 — CONTRATO CAMBIADO POR DECISIÓN DE PRODUCTO.
     *
     * Este test afirmaba antes que la reversión manual se ejecutaba aunque
     * dejara el saldo en negativo. Contradecía a `AGENTS.md` («Dinero y
     * créditos»), que dice literalmente **"No permitas saldos negativos"**.
     *
     * En la reversión MANUAL no hay dinero saliendo de MOVA: es una corrección
     * administrativa. Descontar créditos ya consumidos fabricaría una deuda
     * que nadie decidió. Ahora se rechaza ANTES de escribir nada.
     */
    public function test_a_manual_reversal_that_would_go_negative_is_refused_without_touching_anything(): void
    {
        Notification::fake();
        // El profesor ya gastó 13 de los 15 créditos: solo le quedan 2.
        [, $profile, $recharge] = $this->approvedRecharge(credits: 15, availableAfter: 2);

        $this->actingAs($this->admin())
            ->post(route('admin.recharges.reverse', $recharge), [
                'reason' => 'Abono nunca recibido; el profesor ya consumió los créditos.',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        // NADA se movió: ni estado, ni saldo, ni ledger.
        $this->assertSame('approved', $recharge->fresh()->status);
        $this->assertSame(2, $profile->fresh()->credits_available);
        $this->assertDatabaseMissing('credit_transactions', [
            'idempotency_key' => "recharge:{$recharge->id}:reversal",
        ]);

        // Pero el caso escala a un humano.
        $this->assertDatabaseHas('operational_alerts', [
            'alert_key' => "recharge:{$recharge->id}:manual_reversal_blocked",
            'severity' => OperationalAlert::SEVERITY_CRITICAL,
            'resolved_at' => null,
        ]);
    }

    public function test_a_manual_reversal_with_exactly_enough_credits_is_allowed(): void
    {
        Notification::fake();
        // Límite exacto: 15 créditos disponibles para revertir 15.
        [, $profile, $recharge] = $this->approvedRecharge(credits: 15, availableAfter: 15);

        $this->actingAs($this->admin())
            ->post(route('admin.recharges.reverse', $recharge), [
                'reason' => 'Comprobante duplicado detectado en conciliación.',
            ])
            ->assertSessionHas('success');

        $this->assertSame('reversed', $recharge->fresh()->status);
        $this->assertSame(0, $profile->fresh()->credits_available);
    }

    /**
     * §7 — CAMINO B: la conciliación del proveedor SÍ puede dejar saldo
     * negativo, y debe.
     *
     * Aquí el dinero YA volvió al pagador (reembolso total o contracargo
     * confirmado por Mercado Pago). Negarse a registrarlo dejaría a MOVA con
     * créditos vivos que ningún pago respalda: el ledger dejaría de describir la
     * realidad, que es peor que un saldo negativo. El negativo es el registro
     * honesto de una deuda real.
     *
     * `$actorId === null` es la marca de "esto lo confirmó el proveedor", el
     * mismo criterio que ya usa credit().
     */
    public function test_a_provider_confirmed_reversal_may_leave_a_negative_balance(): void
    {
        Notification::fake();
        [, $profile, $recharge] = $this->approvedRecharge(credits: 15, availableAfter: 2);

        app(\App\Services\RechargeApprovalService::class)->reverse(
            $recharge,
            null, // sin actor humano: verdad del proveedor
            'Reembolso total confirmado por Mercado Pago'
        );

        $this->assertSame('reversed', $recharge->fresh()->status);
        $this->assertSame(-13, $profile->fresh()->credits_available);
        $this->assertDatabaseHas('credit_transactions', [
            'idempotency_key' => "recharge:{$recharge->id}:reversal",
            'amount' => -15,
        ]);
    }
}
