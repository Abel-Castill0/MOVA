<?php

namespace Tests\Feature;

use App\Models\OperationalAlert;
use App\Models\PaymentOrder;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Notifications\RechargeApprovedNotification;
use App\Notifications\RechargeReversedNotification;
use App\Services\CulqiPaymentReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Un fallo del correo (SMTP caído) jamás puede deshacer ni bloquear un asiento del ledger.
 * Antes, credit()/reverse() notificaban DENTRO de la transacción de applyTruth: con la cola `sync` del web de
 * producción la excepción de correo revertía la acreditación de un pago ya confirmado por Culqi.
 */
class RechargeNotificationResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payments.culqi.env' => 'test',
            'queue.default' => 'sync',
            // SMTP real hacia un puerto cerrado: falla de verdad (MailDeliveryException), sin mockear el canal.
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 1,
        ]);
    }

    public function test_a_failing_mail_transport_does_not_roll_back_the_credit_of_a_confirmed_payment(): void
    {
        [$recharge, $order] = $this->submittedCharge();

        $result = app(CulqiPaymentReconciliationService::class)->applyTruth($order, null, $this->charge($order));

        $this->assertSame('paid', $result);
        $this->assertSame('approved', $recharge->fresh()->status);
        $this->assertSame(1, DB::table('credit_transactions')->where('idempotency_key', "recharge:{$recharge->id}:deposit")->count());
        $this->assertSame(5, (int) $recharge->teacherProfile->fresh()->credits_available);
    }

    public function test_a_failing_mail_transport_does_not_roll_back_a_confirmed_full_refund(): void
    {
        [$recharge, $order] = $this->submittedCharge();
        $reconciler = app(CulqiPaymentReconciliationService::class);
        $reconciler->applyTruth($order, null, $this->charge($order));

        $result = $reconciler->applyTruth($order->fresh(), null, array_replace($this->charge($order), ['amount_refunded' => 1000, 'current_amount' => 0]));

        $this->assertSame('reversed', $result);
        $this->assertSame('reversed', $recharge->fresh()->status);
        $this->assertSame(1, DB::table('credit_transactions')->where('idempotency_key', "recharge:{$recharge->id}:reversal")->count());
        $this->assertSame(0, (int) $recharge->teacherProfile->fresh()->credits_available);
    }

    public function test_the_teacher_is_still_notified_exactly_once_when_the_mail_works(): void
    {
        Notification::fake();
        [$recharge, $order] = $this->submittedCharge();
        $reconciler = app(CulqiPaymentReconciliationService::class);

        $reconciler->applyTruth($order, null, $this->charge($order));
        $reconciler->applyTruth($order->fresh(), null, $this->charge($order)); // repetición idempotente

        Notification::assertSentToTimes($recharge->teacherProfile->user, RechargeApprovedNotification::class, 1);
        Notification::assertNotSentTo($recharge->teacherProfile->user, RechargeReversedNotification::class);
    }

    public function test_a_failed_notice_leaves_a_visible_alert_and_never_touches_the_ledger(): void
    {
        [$recharge, $order] = $this->submittedCharge();

        app(CulqiPaymentReconciliationService::class)->applyTruth($order, null, $this->charge($order));

        $alert = OperationalAlert::where('alert_key', "recharge:{$recharge->id}:notification_failed:RechargeApprovedNotification")->first();
        $this->assertNotNull($alert);
        $this->assertSame(OperationalAlert::TYPE_PAYMENT_REVIEW, $alert->type);
        $this->assertStringNotContainsString('127.0.0.1', (string) json_encode($alert->context)); // sin detalles del transporte
        $this->assertSame(1, DB::table('credit_transactions')->where('idempotency_key', "recharge:{$recharge->id}:deposit")->count());
    }

    public function test_no_notice_is_sent_when_the_outer_transaction_rolls_back_after_the_credit(): void
    {
        Notification::fake();
        [$recharge, $order] = $this->submittedCharge();

        try {
            DB::transaction(function () use ($order) {
                app(CulqiPaymentReconciliationService::class)->applyTruth($order, null, $this->charge($order));

                throw new RuntimeException('fallo posterior dentro de la transacción exterior');
            });
        } catch (RuntimeException) {
        }

        Notification::assertNothingSent();
        $this->assertSame('pending', $recharge->fresh()->status);
        $this->assertSame(0, DB::table('credit_transactions')->where('teacher_profile_id', $recharge->teacher_profile_id)->count());
    }

    /** @return array{0: RechargeRequest, 1: PaymentOrder} */
    private function submittedCharge(): array
    {
        $user = User::factory()->create(['phone' => '987654321']);
        $profile = TeacherProfile::create(['user_id' => $user->id, 'is_verified' => true, 'credits_available' => 0, 'credits_reserved' => 0]);
        $recharge = RechargeRequest::create([
            'teacher_profile_id' => $profile->id, 'package_code' => 'inicio', 'package_name' => 'Inicio', 'credits' => 5,
            'amount_pen' => '10.00', 'payment_method' => 'culqi', 'operation_number' => null, 'operation_number_normalized' => null, 'status' => 'pending',
        ]);
        $order = PaymentOrder::create([
            'recharge_request_id' => $recharge->id, 'attempt_number' => 1, 'idempotency_key' => (string) Str::uuid(), 'provider' => 'culqi',
            'provider_order_id' => 'chr_test_NOTIFYRESILIENCE1', 'submission_status' => 'submitted', 'status' => 'pending',
            'amount_minor' => 1000, 'currency' => 'PEN', 'payment_channel' => 'card',
        ]);

        return [$recharge, $order];
    }

    private function charge(PaymentOrder $order): array
    {
        return [
            'object' => 'charge', 'id' => $order->provider_order_id, 'amount' => 1000, 'amount_refunded' => 0, 'current_amount' => 1000,
            'currency_code' => 'PEN', 'email' => 'a@b.pe', 'capture' => true, 'paid' => false, 'dispute' => false, 'duplicated' => false,
            'outcome' => ['type' => 'venta_exitosa', 'code' => 'AUT0000'],
            'metadata' => [
                'payment_order_id' => (string) $order->id, 'recharge_request_id' => (string) $order->recharge_request_id,
                'attempt' => '1', 'idempotency_key' => (string) $order->idempotency_key,
            ],
        ];
    }
}
