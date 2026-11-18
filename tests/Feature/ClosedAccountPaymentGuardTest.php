<?php

namespace Tests\Feature;

use App\Models\{CreditTransaction, OperationalAlert, PaymentOrder, RechargeRequest, TeacherProfile, User};
use App\Payment\Contracts\CardPaymentInstrument;
use App\Payment\CulqiPaymentProvider;
use App\Services\RechargeApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Notification};
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Cuenta cerrada vs. dinero (hallazgo O-01/O-02 de la auditoría 2026-11-17):
 *  - no se inicia un cobro nuevo contra un profesor con la cuenta cerrada;
 *  - un administrador no puede aprobar a mano una recarga de una cuenta cerrada;
 *  - un pago YA confirmado por el proveedor sí se asienta (el ledger describe la realidad, el dinero salió del pagador)
 *    pero deja una incidencia visible para devolverlo, en lugar de quedar acreditado en silencio a una cuenta suprimida.
 */
class ClosedAccountPaymentGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        config([
            'payments.enabled' => true,
            'payments.provider' => 'culqi',
            'payments.culqi.env' => 'test',
            'payments.culqi.public_key' => 'pk_test_AbCdEf0123456789',
            'payments.culqi.private_key' => 'sk_test_AbCdEf0123456789',
            'payments.culqi.encrypt_payloads' => false,
        ]);
        Notification::fake();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function recharge(string $method = 'culqi', bool $closed = false): RechargeRequest
    {
        $user = User::factory()->create(['account_closed_at' => $closed ? now() : null]);
        $user->assignRole('teacher');
        $profile = TeacherProfile::create(['user_id' => $user->id, 'is_verified' => true, 'credits_available' => 0, 'credits_reserved' => 0]);

        return RechargeRequest::create([
            'teacher_profile_id' => $profile->id, 'package_code' => 'inicio', 'package_name' => 'Inicio', 'credits' => 5,
            'amount_pen' => '10.00', 'payment_method' => $method, 'operation_number' => null, 'operation_number_normalized' => null, 'status' => 'pending',
        ]);
    }

    private function paidOrder(RechargeRequest $recharge): PaymentOrder
    {
        return PaymentOrder::create([
            'recharge_request_id' => $recharge->id, 'attempt_number' => 1, 'idempotency_key' => (string) Str::uuid(), 'provider' => 'culqi',
            'provider_order_id' => 'chr_test_CLOSED0000001', 'submission_status' => 'submitted', 'status' => 'paid', 'amount_minor' => 1000, 'currency' => 'PEN',
        ]);
    }

    public function test_no_charge_is_started_for_a_closed_account(): void
    {
        Http::fake();
        $recharge = $this->recharge(closed: true);

        try {
            app(CulqiPaymentProvider::class)->createPaymentAttempt($recharge, new CardPaymentInstrument('tkn_test_abc123XYZ456', 'visa'));
            $this->fail('Debió rechazar el cobro de una cuenta cerrada.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cuenta cerrada', $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame(0, PaymentOrder::count(), 'No se crea ni siquiera el intento.');
        $this->assertSame('pending', $recharge->fresh()->status);
    }

    public function test_no_order_attempt_is_started_for_a_closed_account(): void
    {
        Http::fake();
        $recharge = $this->recharge(closed: true);
        $recharge->teacherProfile->user->forceFill(['phone' => '987654321'])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cuenta cerrada');

        try {
            app(CulqiPaymentProvider::class)->createOrderAttempt($recharge->fresh());
        } finally {
            Http::assertNothingSent();
            $this->assertSame(0, PaymentOrder::count());
        }
    }

    public function test_an_admin_cannot_manually_approve_a_recharge_of_a_closed_account(): void
    {
        $recharge = $this->recharge(method: 'yape', closed: true);

        try {
            app(RechargeApprovalService::class)->credit($recharge, $this->admin()->id);
            $this->fail('Debió rechazar la aprobación manual.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame('pending', $recharge->fresh()->status);
        $this->assertSame(0, CreditTransaction::count());
        $this->assertSame(0, $recharge->teacherProfile->fresh()->credits_available);
    }

    public function test_a_provider_confirmed_payment_on_a_closed_account_is_recorded_and_raises_an_incident(): void
    {
        $recharge = $this->recharge(closed: true);
        $this->paidOrder($recharge);

        $result = app(RechargeApprovalService::class)->credit($recharge, null);

        $this->assertTrue($result['changed']);
        $this->assertSame('approved', $recharge->fresh()->status);
        $this->assertSame(1, CreditTransaction::where('recharge_request_id', $recharge->id)->where('type', 'deposit')->count(), 'El ledger refleja el cobro real.');
        $alert = OperationalAlert::where('alert_key', "recharge:{$recharge->id}:closed-account-payment")->first();
        $this->assertNotNull($alert, 'Debe quedar una incidencia para devolver el pago.');
        $this->assertSame(OperationalAlert::TYPE_PAYMENT_REVIEW, $alert->type);
    }

    public function test_the_provider_confirmed_credit_stays_exactly_once_and_raises_a_single_incident(): void
    {
        $recharge = $this->recharge(closed: true);
        $this->paidOrder($recharge);
        $service = app(RechargeApprovalService::class);

        $service->credit($recharge, null);
        $again = $service->credit($recharge->fresh(), null);

        $this->assertFalse($again['changed']);
        $this->assertSame(1, CreditTransaction::where('type', 'deposit')->count());
        $this->assertSame(1, OperationalAlert::where('alert_key', "recharge:{$recharge->id}:closed-account-payment")->count());
    }

    public function test_an_account_closed_after_the_credit_but_before_the_outer_commit_still_raises_the_incident(): void
    {
        // El abono ve la cuenta abierta; el cierre confirma antes del commit más externo (p. ej. conciliación que envuelve
        // a credit()). La decisión se toma tras el commit, no dentro de la transacción: si no, el aviso se perdería.
        $recharge = $this->recharge();
        $this->paidOrder($recharge);
        $user = $recharge->teacherProfile->user;

        \Illuminate\Support\Facades\DB::transaction(function () use ($recharge, $user) {
            app(RechargeApprovalService::class)->credit($recharge, null);
            $this->assertSame(0, OperationalAlert::count(), 'Todavía no se evalúa: el commit exterior no ha ocurrido.');
            $user->forceFill(['account_closed_at' => now()])->save();
        });

        $this->assertSame(1, OperationalAlert::where('alert_key', "recharge:{$recharge->id}:closed-account-payment")->count());
        $this->assertSame(1, CreditTransaction::where('type', 'deposit')->count());
    }

    public function test_a_failing_alert_never_undoes_or_blocks_the_credit(): void
    {
        $recharge = $this->recharge(closed: true);
        $this->paidOrder($recharge);
        $this->mock(\App\Services\OperationalAlertService::class, fn ($mock) => $mock->shouldReceive('raise')->andThrow(new RuntimeException('alerta caída')));

        $result = app(RechargeApprovalService::class)->credit($recharge, null);

        $this->assertTrue($result['changed']);
        $this->assertSame('approved', $recharge->fresh()->status);
        $this->assertSame(1, CreditTransaction::where('type', 'deposit')->count());
    }

    public function test_an_already_approved_recharge_of_a_closed_account_is_idempotent_even_for_an_admin(): void
    {
        $recharge = $this->recharge(method: 'yape');
        app(RechargeApprovalService::class)->credit($recharge, $this->admin()->id);
        $recharge->teacherProfile->user->forceFill(['account_closed_at' => now()])->save();

        $again = app(RechargeApprovalService::class)->credit($recharge->fresh(), $this->admin()->id);

        $this->assertFalse($again['changed']);
        $this->assertSame(1, CreditTransaction::where('type', 'deposit')->count());
    }

    public function test_open_accounts_are_unaffected(): void
    {
        $recharge = $this->recharge(method: 'yape');

        $result = app(RechargeApprovalService::class)->credit($recharge, $this->admin()->id);

        $this->assertTrue($result['changed']);
        $this->assertSame(5, $recharge->teacherProfile->fresh()->credits_available);
        $this->assertSame(0, OperationalAlert::where('alert_key', 'like', '%closed-account-payment')->count());
    }
}
