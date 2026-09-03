<?php

namespace Tests\Feature;

use App\Jobs\ProcessMercadoPagoWebhook;
use App\Models\CreditTransaction;
use App\Models\PaymentOrder;
use App\Models\PaymentWebhook;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Payment\Money;
use App\Services\MercadoPagoWebhookRecoveryService;
use App\Services\RechargeApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Cubre MercadoPagoWebhookRecoveryService — cuatro pasadas acotadas:
 * webhooks 'received'/'failed' atascados, payment_orders 'pending'
 * atascadas, y payment_orders 'paid' dentro de un lookback (detecta un
 * refund cuyo webhook nunca llegó). Nunca duplica créditos (ver
 * ProcessMercadoPagoWebhookJobTest para la idempotencia del abono en sí).
 */
class MercadoPagoWebhookRecoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payments.mercadopago.base_url' => 'https://api.mercadopago.com',
            'payments.mercadopago.access_token' => 'unit-test-placeholder-000',
        ]);
    }

    // ---- payment_webhooks 'received' atascados --------------------------------

    public function test_stale_received_webhook_is_requeued(): void
    {
        Queue::fake();
        [, , , $webhook] = $this->scenario();
        $webhook->update(['received_at' => now()->subMinutes(30)]);

        $result = $this->recover(staleReceivedMinutes: 15, stuckOrderMinutes: 999);

        $this->assertSame(1, $result['stale_received']);
        Queue::assertPushed(ProcessMercadoPagoWebhook::class, fn ($job) => $job->paymentWebhookId === $webhook->id);
    }

    public function test_recent_received_webhook_is_not_requeued(): void
    {
        Queue::fake();
        $this->scenario(); // received_at = now(), por debajo del umbral

        $result = $this->recover(staleReceivedMinutes: 15, stuckOrderMinutes: 999);

        $this->assertSame(0, $result['stale_received']);
        Queue::assertNothingPushed();
    }

    // ---- payment_webhooks 'failed' — retry budget del RECOVERY ----------------

    public function test_failed_webhook_is_reset_and_requeued_when_under_retry_budget(): void
    {
        Queue::fake();
        [, , , $webhook] = $this->scenario();
        $webhook->update(['status' => 'failed', 'error' => 'MP_API_DOWN: algo falló', 'recovery_attempts' => 0]);

        $result = $this->recover(staleReceivedMinutes: 999, stuckOrderMinutes: 999, maxRecoveryAttempts: 3);

        $this->assertSame(1, $result['failed_requeued']);
        $this->assertSame(0, $result['failed_exhausted_to_review']);
        $webhook->refresh();
        $this->assertSame('received', $webhook->status);
        $this->assertNull($webhook->error);
        $this->assertSame(1, $webhook->recovery_attempts);
        Queue::assertPushed(ProcessMercadoPagoWebhook::class, fn ($job) => $job->paymentWebhookId === $webhook->id);
    }

    public function test_failed_webhook_exhausts_retry_budget_and_falls_to_review_instead_of_looping_forever(): void
    {
        Queue::fake();
        [, , , $webhook] = $this->scenario();
        $webhook->update(['status' => 'failed', 'recovery_attempts' => 3]); // ya en el tope

        $result = $this->recover(staleReceivedMinutes: 999, stuckOrderMinutes: 999, maxRecoveryAttempts: 3);

        $this->assertSame(0, $result['failed_requeued']);
        $this->assertSame(1, $result['failed_exhausted_to_review']);
        $webhook->refresh();
        $this->assertSame('review', $webhook->status);
        $this->assertStringContainsString('Reintentos de recuperación agotados', $webhook->error);
        Queue::assertNothingPushed();
    }

    public function test_failed_webhook_exhaustion_also_persists_review_reason_on_the_matching_payment_order(): void
    {
        // REVIEW DURABILITY: no solo en payment_webhooks — un operador debe
        // poder llegar a esto navegando desde la PaymentOrder también.
        Queue::fake();
        [$order, , , $webhook] = $this->scenario();
        $webhook->update(['status' => 'failed', 'recovery_attempts' => 3]);

        $this->recover(staleReceivedMinutes: 999, stuckOrderMinutes: 999, maxRecoveryAttempts: 3);

        $fresh = $order->fresh();
        $this->assertNotNull($fresh->review_reason);
        $this->assertStringContainsString('Reintentos de recuperación agotados', $fresh->review_reason);
        $this->assertNotNull($fresh->last_verified_at);
    }

    // ---- payment_orders 'pending' atascadas -----------------------------------

    public function test_stuck_order_without_any_webhook_is_reconciled_directly_and_credits(): void
    {
        // El caso central: Mercado Pago nunca entregó NINGÚN webhook para
        // esta order (perdido) — no hay payment_webhooks que reencolar, así
        // que el recovery debe reconciliar la PaymentOrder directamente.
        [$order, $recharge, $profile] = $this->scenario(withWebhook: false, credits: 5, amountPen: '10.00');
        $order->timestamps = false;
        $order->forceFill(['updated_at' => now()->subMinutes(45)])->save();

        $this->fakePayment($order, $recharge, 'approved', 'accredited', 10.0);

        $result = $this->recover(staleReceivedMinutes: 999, stuckOrderMinutes: 30);

        $this->assertSame(1, $result['stuck_orders_reconciled']);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "recharge:{$recharge->id}:deposit")->count());
    }

    public function test_recovering_the_same_stuck_order_twice_never_duplicates_credit(): void
    {
        [$order, $recharge, $profile] = $this->scenario(withWebhook: false, credits: 5, amountPen: '10.00');
        $order->timestamps = false;
        $order->forceFill(['updated_at' => now()->subMinutes(45)])->save();

        $this->fakePayment($order, $recharge, 'approved', 'accredited', 10.0);

        $this->recover(staleReceivedMinutes: 999, stuckOrderMinutes: 30);
        // La order ya quedó 'paid' — el segundo barrido ni siquiera la
        // vuelve a seleccionar (el filtro es status='pending').
        $this->recover(staleReceivedMinutes: 999, stuckOrderMinutes: 30);

        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "recharge:{$recharge->id}:deposit")->count());
    }

    public function test_recently_updated_pending_order_is_not_touched(): void
    {
        [$order] = $this->scenario(withWebhook: false); // updated_at = now()
        Http::fake(); // cualquier llamada sería un bug

        $result = $this->recover(staleReceivedMinutes: 999, stuckOrderMinutes: 30);

        $this->assertSame(0, $result['stuck_orders_reconciled']);
        Http::assertNothingSent();
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_stuck_order_never_confirmed_by_mercadopago_is_excluded_from_the_pass(): void
    {
        // provider_order_id NULL (el POST se perdió por una excepción de
        // red antes de recibir respuesta — ver
        // MercadoPagoPaymentProvider::resolveAttemptRow()): no hay ningún
        // `id` que consultar, y MOVA no puede reintentar el POST por sí
        // sola (el token es de un solo uso, nunca se persiste). Sin este
        // filtro, reconcile() la marcaría 'errored' en cada barrido para
        // siempre.
        [$order] = $this->scenario(withWebhook: false);
        $order->timestamps = false;
        $order->forceFill(['provider_order_id' => null, 'updated_at' => now()->subMinutes(45)])->save();
        Http::fake(); // cualquier llamada sería un bug

        $result = $this->recover(staleReceivedMinutes: 999, stuckOrderMinutes: 30);

        $this->assertSame(0, $result['stuck_orders_reconciled']);
        $this->assertSame(0, $result['stuck_orders_errored']);
        Http::assertNothingSent();
    }

    public function test_fetch_payment_failure_during_recovery_is_counted_as_errored_not_fatal(): void
    {
        [$order] = $this->scenario(withWebhook: false);
        $order->timestamps = false;
        $order->forceFill(['updated_at' => now()->subMinutes(45)])->save();
        Http::fake(["api.mercadopago.com/v1/payments/{$order->provider_order_id}" => Http::response(['message' => 'down'], 500)]);

        $result = $this->recover(staleReceivedMinutes: 999, stuckOrderMinutes: 30);

        $this->assertSame(1, $result['stuck_orders_errored']);
        $this->assertSame(0, $result['stuck_orders_reconciled']);
    }

    // ---- payment_orders 'paid' dentro del lookback (refund perdido) ----------

    public function test_paid_order_within_lookback_that_was_refunded_is_detected_and_reversed(): void
    {
        [$order, $recharge, $profile] = $this->scenario(withWebhook: false, credits: 5, amountPen: '10.00');
        $this->markPaid($order, hoursAgo: 3);
        app(RechargeApprovalService::class)->credit($recharge, null);

        $this->fakePayment($order, $recharge, 'refunded', 'refunded', 10.0);

        $result = $this->recover(paidLookbackDays: 7, paidLookbackMinAgeMinutes: 60);

        $this->assertSame(1, $result['paid_lookback_reconciled']);
        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertSame('reversed', $recharge->fresh()->status);
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "recharge:{$recharge->id}:reversal")->count());
    }

    public function test_paid_order_within_lookback_still_paid_is_a_noop(): void
    {
        [$order, $recharge, $profile] = $this->scenario(withWebhook: false, credits: 5, amountPen: '10.00');
        $this->markPaid($order, hoursAgo: 3);
        app(RechargeApprovalService::class)->credit($recharge, null);

        $this->fakePayment($order, $recharge, 'approved', 'accredited', 10.0);

        $result = $this->recover(paidLookbackDays: 7, paidLookbackMinAgeMinutes: 60);

        $this->assertSame(1, $result['paid_lookback_reconciled']);
        $this->assertSame(5, $profile->fresh()->credits_available); // sin duplicar
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "recharge:{$recharge->id}:deposit")->count());
    }

    public function test_paid_order_too_recent_is_not_touched_by_lookback(): void
    {
        [$order] = $this->scenario(withWebhook: false, credits: 5, amountPen: '10.00');
        $this->markPaid($order, hoursAgo: 0); // recién pagado — dentro de la latencia normal de un webhook

        Http::fake(); // cualquier llamada sería un bug

        $result = $this->recover(paidLookbackDays: 7, paidLookbackMinAgeMinutes: 60);

        $this->assertSame(0, $result['paid_lookback_reconciled']);
        Http::assertNothingSent();
    }

    public function test_paid_order_outside_lookback_window_is_not_touched(): void
    {
        [$order] = $this->scenario(withWebhook: false, credits: 5, amountPen: '10.00');
        $this->markPaid($order, hoursAgo: 24 * 30); // muy viejo — fuera de la ventana

        Http::fake();

        $result = $this->recover(paidLookbackDays: 7, paidLookbackMinAgeMinutes: 60);

        $this->assertSame(0, $result['paid_lookback_reconciled']);
        Http::assertNothingSent();
    }

    // ---- payment_orders inciertas (UNKNOWN PAYMENT RECOVERY, en lote) --------

    /**
     * La lógica de resolución en sí (0/1/N resultados, budget, review) ya
     * está cubierta exhaustivamente en MercadoPagoUncertainSubmissionRecoveryTest
     * — aquí solo se prueba el CRITERIO DE SELECCIÓN del barrido en lote:
     * edad mínima, provider_order_id NULL, submission_status='uncertain'.
     */
    public function test_uncertain_order_old_enough_is_picked_up_by_the_batch_pass(): void
    {
        [$order] = $this->scenario(withWebhook: false);
        $order->timestamps = false;
        $order->forceFill([
            'provider_order_id' => null,
            'submission_status' => 'uncertain',
            'updated_at' => now()->subMinutes(30),
        ])->save();

        Http::fake(['api.mercadopago.com/v1/payments/search*' => Http::response(
            ['paging' => ['total' => 0, 'limit' => 10, 'offset' => 0], 'results' => []],
            200
        )]);

        $resultWithUncertainWindow = app(MercadoPagoWebhookRecoveryService::class)->recover(
            staleReceivedMinutes: 999,
            stuckOrderMinutes: 999,
            paidLookbackDays: 999,
            paidLookbackMinAgeMinutes: 999999,
            maxRecoveryAttempts: 3,
            batchSize: 200,
            uncertainMinAgeMinutes: 5,
            uncertainMaxAttempts: 5,
        );

        $this->assertSame(1, $resultWithUncertainWindow['uncertain_still_uncertain']);
        $this->assertSame('uncertain', $order->fresh()->submission_status);
        $this->assertSame(1, $order->fresh()->recovery_attempts);
    }

    public function test_uncertain_order_too_recent_is_not_touched_by_the_batch_pass(): void
    {
        [$order] = $this->scenario(withWebhook: false);
        $order->forceFill(['provider_order_id' => null, 'submission_status' => 'uncertain'])->save(); // updated_at = now()

        Http::fake(); // cualquier llamada sería un bug

        $result = app(MercadoPagoWebhookRecoveryService::class)->recover(
            staleReceivedMinutes: 999,
            stuckOrderMinutes: 999,
            paidLookbackDays: 999,
            paidLookbackMinAgeMinutes: 999999,
            maxRecoveryAttempts: 3,
            batchSize: 200,
            uncertainMinAgeMinutes: 5,
            uncertainMaxAttempts: 5,
        );

        $this->assertSame(0, $result['uncertain_still_uncertain']);
        $this->assertSame(0, $result['uncertain_resolved']);
        Http::assertNothingSent();
    }

    public function test_uncertain_order_with_a_confirmed_provider_id_is_excluded_from_the_batch_pass(): void
    {
        // Ya tiene provider_order_id — no es un "unknown payment" (eso lo
        // cubre reconcileStuckOrders, la pasada 3), y no hay ningún
        // external_reference que buscar: ya se sabe cuál es el pago.
        [$order] = $this->scenario(withWebhook: false);
        $order->timestamps = false;
        $order->forceFill(['submission_status' => 'uncertain', 'updated_at' => now()->subMinutes(30)])->save();

        Http::fake(); // cualquier llamada sería un bug

        $result = app(MercadoPagoWebhookRecoveryService::class)->recover(
            staleReceivedMinutes: 999,
            stuckOrderMinutes: 999,
            paidLookbackDays: 999,
            paidLookbackMinAgeMinutes: 999999,
            maxRecoveryAttempts: 3,
            batchSize: 200,
            uncertainMinAgeMinutes: 5,
            uncertainMaxAttempts: 5,
        );

        $this->assertSame(0, $result['uncertain_still_uncertain']);
        $this->assertSame(0, $result['uncertain_resolved']);
        Http::assertNothingSent();
    }

    // ---- helpers --------------------------------------------------------------

    private function recover(
        ?int $staleReceivedMinutes = null,
        ?int $stuckOrderMinutes = null,
        ?int $paidLookbackDays = null,
        ?int $paidLookbackMinAgeMinutes = null,
        ?int $maxRecoveryAttempts = null,
        ?int $batchSize = null,
    ): array {
        return app(MercadoPagoWebhookRecoveryService::class)->recover(
            $staleReceivedMinutes ?? 999,
            $stuckOrderMinutes ?? 999,
            $paidLookbackDays ?? 999,
            $paidLookbackMinAgeMinutes ?? 999999,
            $maxRecoveryAttempts ?? 3,
            $batchSize ?? 200,
        );
    }

    /**
     * @return array{0:PaymentOrder,1:RechargeRequest,2:TeacherProfile,3:?PaymentWebhook}
     */
    private function scenario(bool $withWebhook = true, int $credits = 5, string $amountPen = '10.00'): array
    {
        $teacher = User::factory()->create(['password' => 'password']);
        $teacher->assignRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_verified' => true,
            'credits_available' => 0,
            'credits_reserved' => 0,
        ]);

        $operation = fake()->unique()->numerify('OP########');
        $recharge = RechargeRequest::create([
            'teacher_profile_id' => $profile->id,
            'package_code' => 'inicio',
            'package_name' => 'Inicio',
            'credits' => $credits,
            'amount_pen' => $amountPen,
            'payment_method' => 'mercadopago',
            'operation_number' => $operation,
            'operation_number_normalized' => $operation,
            'status' => 'pending',
        ]);

        $order = PaymentOrder::create([
            'recharge_request_id' => $recharge->id,
            'attempt_number' => 1,
            'provider' => 'mercadopago',
            'provider_order_id' => 'PAY-'.$recharge->id,
            'status' => 'pending',
            'amount_minor' => Money::solesToMinor($amountPen),
            'currency' => 'PEN',
        ]);

        $webhook = null;
        if ($withWebhook) {
            $webhook = PaymentWebhook::create([
                'provider' => 'mercadopago',
                'event_id' => 'evt-'.$recharge->id,
                'event_type' => 'payment.updated',
                'payload' => ['id' => 'evt-'.$recharge->id, 'data' => ['id' => $order->provider_order_id]],
                'payload_hash' => hash('sha256', (string) $recharge->id),
                'received_at' => now(),
                'status' => 'received',
            ]);
        }

        return [$order, $recharge, $profile, $webhook];
    }

    private function markPaid(PaymentOrder $order, int $hoursAgo): void
    {
        $order->timestamps = false;
        $order->forceFill(['status' => 'paid', 'paid_at' => now()->subHours($hoursAgo)])->save();
    }

    private function fakePayment(PaymentOrder $order, RechargeRequest $recharge, string $status, ?string $statusDetail, float $transactionAmount): void
    {
        // REFUND TRUTH: si el status/status_detail es un reversal total
        // confirmado, transaction_amount_refunded se completa con el monto
        // total (caso feliz) — igual convención que
        // ProcessMercadoPagoWebhookJobTest::paymentTruthBody().
        $transactionAmountRefunded = ($status === 'refunded' && in_array($statusDetail, ['refunded', 'by_admin'], true))
            ? $transactionAmount
            : 0.0;

        Http::fake([
            "api.mercadopago.com/v1/payments/{$order->provider_order_id}" => Http::response([
                'id' => $order->provider_order_id,
                'status' => $status,
                'status_detail' => $statusDetail,
                'transaction_amount' => $transactionAmount,
                'transaction_amount_refunded' => $transactionAmountRefunded,
                'external_reference' => "recharge:{$recharge->id}:attempt:{$order->attempt_number}",
                'currency_id' => 'PEN',
            ], 200),
        ]);
    }
}
