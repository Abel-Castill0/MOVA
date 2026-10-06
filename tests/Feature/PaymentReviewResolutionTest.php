<?php

namespace Tests\Feature;

use App\Exceptions\PaymentReviewCannotBeClosed;
use App\Models\CreditTransaction;
use App\Models\OperationalAlert;
use App\Models\PaymentOrder;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\PaymentReviewResolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Incidente 2026-10-06 (recarga 1, Yape): MP rechazó la creación (HTTP 400 / 2072) y el intento quedó en revisión sin forma
 * canónica de cerrarlo. El cierre administrativo es ACOTADO: solo con evidencia persistida de un rechazo terminal Y búsqueda remota
 * sin pagos; nunca acredita, reembolsa, borra ni toca el ledger, y nunca cierra si la existencia del pago sigue incierta.
 */
class PaymentReviewResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        config([
            'payments.mercadopago.base_url' => 'https://api.mercadopago.com',
            'payments.mercadopago.access_token' => 'review-test-placeholder-000',
        ]);
    }

    // ---- camino feliz ------------------------------------------------------

    public function test_admin_closes_a_terminally_rejected_attempt_when_the_provider_has_no_payment(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/search*' => Http::response(['results' => [], 'paging' => ['total' => 0]], 200)]);
        $admin = $this->admin();
        [$recharge, $order] = $this->reviewedAttempt();
        $alert = $this->alertFor($order);

        $this->actingAs($admin)->post(route('admin.recharges.close-rejected-payment', $recharge), [
            'reason' => 'Revisado: MP confirma 0 pagos y la creación fue un 400 terminal.',
        ])->assertRedirect()->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('failed', $order->status);
        $this->assertNull($order->submission_status);
        $this->assertNull($order->provider_order_id);
        $this->assertNotNull($order->review_resolved_at);
        $this->assertSame('pending', $recharge->fresh()->status, 'La recarga no cambia: solo se cierra el intento.');

        // Ni créditos ni ledger.
        $this->assertSame(0, CreditTransaction::count());
        $this->assertSame(0, $recharge->teacherProfile->fresh()->credits_available);

        // Auditoría en la incidencia: quién, cuándo y por qué.
        $alert->refresh();
        $this->assertNotNull($alert->resolved_at);
        $this->assertSame($admin->id, $alert->resolved_by);
        $this->assertStringContainsString('2072', (string) $alert->resolution_note);
        $this->assertStringContainsString('Revisado: MP confirma', (string) $alert->resolution_note);

        // Solo lecturas: jamás un POST de pago ni un reembolso.
        Http::assertSent(fn ($request) => $request->method() === 'GET');
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_closing_is_idempotent_and_does_not_hit_the_provider_again(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/search*' => Http::response(['results' => []], 200)]);
        $admin = $this->admin();
        [, $order] = $this->reviewedAttempt();

        $service = app(PaymentReviewResolutionService::class);
        $this->assertSame('closed', $service->closeRejectedAttempt($order, $admin, 'Primer cierre con motivo suficiente.'));
        $this->assertSame('already_closed', $service->closeRejectedAttempt($order->fresh(), $admin, 'Segundo intento del mismo cierre.'));

        Http::assertSentCount(1);
        $this->assertSame('failed', $order->fresh()->status);
    }

    // ---- nunca si la existencia del pago es incierta -------------------------

    public function test_it_refuses_when_the_provider_reports_a_payment(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/search*' => Http::response(['results' => [[
            'id' => 123456789, 'status' => 'approved', 'status_detail' => 'accredited', 'transaction_amount' => 1, 'currency_id' => 'PEN',
        ]]], 200)]);
        [, $order] = $this->reviewedAttempt();

        $this->assertCannotClose($order, 'SÍ existe');
        $this->assertUntouched($order);
    }

    public function test_it_refuses_when_the_remote_search_fails(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/search*' => Http::response(['message' => 'internal_error'], 500)]);
        [, $order] = $this->reviewedAttempt();

        $this->assertCannotClose($order, 'No se pudo consultar');
        $this->assertUntouched($order);
    }

    public function test_it_refuses_without_persisted_terminal_rejection_evidence(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/search*' => Http::response(['results' => []], 200)]);

        foreach ([
            'sin evidencia' => ['creation_http_status' => null, 'creation_error_codes' => null],
            'HTTP 500' => ['creation_http_status' => 500, 'creation_error_codes' => 'internal_error'],
            '400 de cuenta (2034)' => ['creation_http_status' => 400, 'creation_error_codes' => 'bad_request,2034'],
            '400 sin código documentado' => ['creation_http_status' => 400, 'creation_error_codes' => 'bad_request'],
        ] as $label => $evidence) {
            [, $order] = $this->reviewedAttempt($evidence);
            $this->assertCannotClose($order, 'evidencia persistida');
            $this->assertUntouched($order, $label);
        }

        Http::assertNothingSent(); // ni siquiera se consulta al proveedor sin evidencia
    }

    public function test_it_refuses_in_every_other_unsafe_state(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/search*' => Http::response(['results' => []], 200)]);

        $cases = [
            'con ID de proveedor' => [['provider_order_id' => '999'], 'no está pendiente sin pago'],
            'ya pagada' => [['status' => 'paid', 'paid_at' => now()], 'no está pendiente sin pago'],
            'sin revisión' => [['review_reason' => null], 'no está en revisión'],
            'envío en curso' => [['submission_status' => 'submitting'], 'podría seguir en curso'],
            'rechazo demasiado reciente' => [['creation_failed_at' => now()->subMinutes(2)], 'demasiado reciente'],
        ];

        foreach ($cases as $label => [$override, $message]) {
            [, $order] = $this->reviewedAttempt([], $override);
            $this->assertCannotClose($order, $message);
            $this->assertSame($order->status, $order->fresh()->status, $label);
        }
    }

    public function test_it_refuses_when_the_ledger_has_movements_or_a_later_attempt_exists(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/search*' => Http::response(['results' => []], 200)]);

        [$recharge, $order] = $this->reviewedAttempt();
        CreditTransaction::create([
            'teacher_profile_id' => $recharge->teacher_profile_id,
            'recharge_request_id' => $recharge->id,
            'idempotency_key' => 'recharge:'.$recharge->id.':deposit',
            'type' => 'deposit',
            'amount' => 1,
            'description' => 'x',
        ]);
        $this->assertCannotClose($order, 'ledger');

        [$recharge2, $order2] = $this->reviewedAttempt();
        PaymentOrder::create([
            'recharge_request_id' => $recharge2->id, 'attempt_number' => 2, 'idempotency_key' => 'k-'.uniqid(), 'provider' => 'mercadopago',
            'status' => 'pending', 'amount_minor' => 100, 'currency' => 'PEN',
        ]);
        $this->assertCannotClose($order2, 'intento posterior');
    }

    // ---- autorización del endpoint -----------------------------------------

    public function test_only_an_admin_can_use_the_endpoint(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/search*' => Http::response(['results' => []], 200)]);
        [$recharge, $order] = $this->reviewedAttempt();

        $teacher = $recharge->teacherProfile->user;
        $this->actingAs($teacher)->post(route('admin.recharges.close-rejected-payment', $recharge), ['reason' => 'Intento de un profesor sin permiso.'])
            ->assertForbidden();
        $this->assertSame('pending', $order->fresh()->status);

        auth()->logout();
        $this->post(route('admin.recharges.close-rejected-payment', $recharge), ['reason' => 'Sin sesión alguna, no debe pasar.'])
            ->assertRedirect(route('login'));

        $this->actingAs($this->admin())->post(route('admin.recharges.close-rejected-payment', $recharge), ['reason' => 'corto'])
            ->assertSessionHasErrors('reason');
        $this->assertSame('pending', $order->fresh()->status);
    }

    // ---- CLI de atestación -----------------------------------------------------

    public function test_the_operator_attestation_only_fills_the_evidence_once_and_never_changes_state(): void
    {
        [, $order] = $this->reviewedAttempt(['creation_http_status' => null, 'creation_error_codes' => null, 'creation_failed_at' => null, 'creation_provider_request_id' => null]);

        $this->artisan('mova:attest-payment-rejection', [
            'order' => $order->id, '--http' => 400, '--code' => ['2072'], '--request-id' => 'req-abc', '--at' => '2026-10-06 04:42:43',
        ])->assertSuccessful();

        $order->refresh();
        $this->assertSame(400, $order->creation_http_status);
        $this->assertSame('2072', $order->creation_error_codes);
        $this->assertSame('pending', $order->status, 'La atestación no cambia el estado.');
        $this->assertNotNull($order->review_reason);

        // No se sobrescribe.
        $this->artisan('mova:attest-payment-rejection', ['order' => $order->id, '--http' => 400, '--code' => ['2072'], '--at' => '2026-10-06 05:00:00'])->assertFailed();
        // Solo 400 con código documentado.
        [, $other] = $this->reviewedAttempt(['creation_http_status' => null, 'creation_error_codes' => null, 'creation_failed_at' => null]);
        $this->artisan('mova:attest-payment-rejection', ['order' => $other->id, '--http' => 500, '--code' => ['internal_error'], '--at' => '2026-10-06 04:00:00'])->assertFailed();
        $this->artisan('mova:attest-payment-rejection', ['order' => $other->id, '--http' => 400, '--code' => ['9999'], '--at' => '2026-10-06 04:00:00'])->assertFailed();
        $this->assertNull($other->fresh()->creation_http_status);
    }

    // ---- helpers ----------------------------------------------------------------

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    /**
     * @param  array<string,mixed>  $evidence  columnas creation_* a sobrescribir
     * @param  array<string,mixed>  $override  otras columnas de la orden
     * @return array{0: RechargeRequest, 1: PaymentOrder}
     */
    private function reviewedAttempt(array $evidence = [], array $override = []): array
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');
        $profile = TeacherProfile::create(['user_id' => $teacher->id, 'is_verified' => true, 'credits_available' => 0, 'credits_reserved' => 0]);

        $recharge = RechargeRequest::create([
            'teacher_profile_id' => $profile->id, 'package_code' => 'verificacion', 'package_name' => 'Verificación de cobro',
            'credits' => 1, 'amount_pen' => '1.00', 'payment_method' => 'mercadopago',
            'operation_number' => null, 'operation_number_normalized' => null, 'status' => 'pending',
        ]);

        $order = PaymentOrder::create(array_merge([
            'recharge_request_id' => $recharge->id, 'attempt_number' => 1, 'idempotency_key' => 'k-'.uniqid(), 'provider' => 'mercadopago',
            'status' => 'pending', 'submission_status' => null, 'recovery_attempts' => 5, 'amount_minor' => 100, 'currency' => 'PEN',
            'review_reason' => 'Búsqueda por external_reference agotó el presupuesto de recuperación.',
            'review_detected_at' => now()->subMinutes(30),
            'creation_http_status' => 400, 'creation_error_codes' => 'bad_request,2072',
            'creation_provider_request_id' => 'req-original', 'creation_failed_at' => now()->subMinutes(30),
        ], $evidence, $override));

        return [$recharge, $order];
    }

    private function alertFor(PaymentOrder $order): OperationalAlert
    {
        return OperationalAlert::create([
            'alert_key' => "payment_order:{$order->id}:review", 'type' => OperationalAlert::TYPE_PAYMENT_REVIEW,
            'severity' => OperationalAlert::SEVERITY_CRITICAL, 'title' => 'Pago de Mercado Pago en revisión', 'message' => 'x',
            'first_detected_at' => now(), 'last_detected_at' => now(), 'occurrences' => 1,
        ]);
    }

    private function assertCannotClose(PaymentOrder $order, string $messageFragment): void
    {
        try {
            app(PaymentReviewResolutionService::class)->closeRejectedAttempt($order, $this->admin(), 'Motivo suficientemente largo para el cierre.');
            $this->fail('Se esperaba PaymentReviewCannotBeClosed.');
        } catch (PaymentReviewCannotBeClosed $e) {
            $this->assertStringContainsString($messageFragment, $e->getMessage());
        }
    }

    private function assertUntouched(PaymentOrder $order, string $label = ''): void
    {
        $fresh = $order->fresh();
        $this->assertSame('pending', $fresh->status, $label);
        $this->assertNull($fresh->review_resolved_at, $label);
        $this->assertSame(0, CreditTransaction::count(), $label);
    }
}
