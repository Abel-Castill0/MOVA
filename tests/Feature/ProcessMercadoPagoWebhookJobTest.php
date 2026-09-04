<?php

namespace Tests\Feature;

use App\Jobs\ProcessMercadoPagoWebhook;
use App\Models\CreditTransaction;
use App\Models\PaymentOrder;
use App\Models\PaymentWebhook;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Payment\MercadoPagoPaymentProvider;
use App\Payment\Money;
use App\Services\MercadoPagoPaymentReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Cubre lo que ProcessMercadoPagoWebhook + MercadoPagoPaymentReconciliationService
 * deciden ante la "verdad financiera" (fetchPayment() server-to-server,
 * nunca el payload del webhook original) — Payments API, tras el pivot
 * desde Orders API. Http::fake() intercepta GET /v1/payments/{id}; el job
 * se ejecuta directamente (sin worker real).
 */
class ProcessMercadoPagoWebhookJobTest extends TestCase
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

    // ---- estados que NUNCA acreditan ----------------------------------------

    /** @dataProvider nonCreditingStatuses */
    public function test_non_terminal_or_failed_statuses_never_credit(string $status, ?string $statusDetail, string $expectedOrderStatus): void
    {
        [$order, $recharge, $profile, $webhook] = $this->scenario();
        $this->fakePaymentTruth($order, $recharge, $status, $statusDetail);

        $this->runJob($webhook);

        $this->assertSame($expectedOrderStatus, $order->fresh()->status);
        $this->assertSame('pending', $recharge->fresh()->status);
        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertDatabaseCount('credit_transactions', 0);
    }

    public static function nonCreditingStatuses(): array
    {
        return [
            'authorized/pending_capture → pending' => ['authorized', 'pending_capture', 'pending'],
            'in_process → pending' => ['in_process', 'pending_review_manual', 'pending'],
            'pending → pending' => ['pending', 'pending_contingency', 'pending'],
            'rejected → failed' => ['rejected', 'cc_rejected_insufficient_amount', 'failed'],
            'cancelled → failed' => ['cancelled', 'by_collector', 'failed'],
        ];
    }

    // ---- pago confirmado -----------------------------------------------------

    public function test_approved_accredited_credits_exactly_once(): void
    {
        [$order, $recharge, $profile, $webhook] = $this->scenario(credits: 5, amountPen: '10.00');
        $this->fakePaymentTruth($order, $recharge, 'approved', 'accredited', transactionAmount: 10.0);

        $this->runJob($webhook);

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('approved', $recharge->fresh()->status);
        $this->assertNull($recharge->fresh()->reviewed_by); // acreditado por el sistema
        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "recharge:{$recharge->id}:deposit")->count());
        $this->assertSame('processed', $webhook->fresh()->status);
    }

    /**
     * 3DS Challenge (MOVA Card Payment Brick 3DS): una vez que la
     * reconciliación resuelve el intento a un estado TERMINAL, los datos
     * del iframe (puramente de presentación, ver docblock de la migración)
     * se limpian — ya no hay ningún Challenge que mostrar.
     */
    public function test_three_ds_challenge_fields_are_cleared_once_the_attempt_resolves(): void
    {
        [$order, $recharge, $profile, $webhook] = $this->scenario(credits: 5, amountPen: '10.00');
        $order->update([
            'provider_status_detail' => 'pending_challenge',
            'three_ds_challenge_url' => 'https://acs-public.tp.mastercard.com/api/v1/browser_Challenges',
            'three_ds_creq' => 'eyJmYWtlIjoiY3JlcSJ9',
            'three_ds_expires_at' => now()->addMinutes(5),
        ]);
        $this->fakePaymentTruth($order, $recharge, 'approved', 'accredited', transactionAmount: 10.0);

        $this->runJob($webhook);

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertNull($order->fresh()->three_ds_challenge_url);
        $this->assertNull($order->fresh()->three_ds_creq);
        $this->assertNull($order->fresh()->three_ds_expires_at);
    }

    /**
     * Mismo criterio que el test 'paid' de arriba, para la otra rama
     * terminal (applyFailed()) — un Challenge que termina en rechazo
     * tampoco debe dejar el iframe/ventana vivos en la fila.
     */
    public function test_three_ds_challenge_fields_are_cleared_when_the_attempt_resolves_to_failed(): void
    {
        [$order, $recharge, , $webhook] = $this->scenario(credits: 5, amountPen: '10.00');
        $order->update([
            'provider_status_detail' => 'pending_challenge',
            'three_ds_challenge_url' => 'https://acs-public.tp.mastercard.com/api/v1/browser_Challenges',
            'three_ds_creq' => 'eyJmYWtlIjoiY3JlcSJ9',
            'three_ds_expires_at' => now()->addMinutes(5),
        ]);
        $this->fakePaymentTruth($order, $recharge, 'rejected', 'cc_rejected_insufficient_amount', transactionAmount: 10.0);

        $this->runJob($webhook);

        $this->assertSame('failed', $order->fresh()->status);
        $this->assertNull($order->fresh()->three_ds_challenge_url);
        $this->assertNull($order->fresh()->three_ds_creq);
        $this->assertNull($order->fresh()->three_ds_expires_at);
    }

    public function test_yape_approved_credits_exactly_once(): void
    {
        // Misma lógica de reconciliación para Yape — no hay una segunda
        // ruta de crédito; solo cambia payment_method_id en la verdad.
        [$order, $recharge, $profile, $webhook] = $this->scenario(credits: 5, amountPen: '10.00');
        $this->fakePaymentTruth($order, $recharge, 'approved', 'accredited', transactionAmount: 10.0, paymentMethodId: 'yape');

        $this->runJob($webhook);

        $this->assertSame(5, $profile->fresh()->credits_available);
    }

    public function test_repeated_paid_notification_does_not_duplicate_credit(): void
    {
        [$order, $recharge, $profile, $webhook] = $this->scenario(credits: 5, amountPen: '10.00');
        $this->fakePaymentTruth($order, $recharge, 'approved', 'accredited', transactionAmount: 10.0);
        $this->runJob($webhook);

        // Segunda notificación DISTINTA (otro event_id) para el MISMO pago
        // ya aprobado — el job debe volver a consultar la verdad y no
        // duplicar nada.
        $secondWebhook = PaymentWebhook::create([
            'provider' => 'mercadopago',
            'event_id' => 'evt-second',
            'event_type' => 'payment.updated',
            'payload' => ['id' => 'evt-second', 'data' => ['id' => $order->provider_order_id]],
            'payload_hash' => hash('sha256', 'x'),
            'received_at' => now(),
            'status' => 'received',
        ]);

        $this->runJob($secondWebhook);

        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "recharge:{$recharge->id}:deposit")->count());
    }

    // ---- reintento del mismo intento vs. nuevo intento (sección 4/16) -------

    public function test_a_new_attempt_after_a_rejected_one_credits_exactly_once_on_the_same_recharge(): void
    {
        // El flujo de producto explícito: intento 1 (tarjeta) rechazado,
        // intento 2 (Yape) sobre la MISMA RechargeRequest aprobado — un
        // solo crédito, sin perder el rastro del intento 1.
        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile, credits: 5, amountPen: '10.00');

        $firstAttempt = PaymentOrder::create([
            'recharge_request_id' => $recharge->id,
            'attempt_number' => 1,
            'provider' => 'mercadopago',
            'provider_order_id' => 'PAY-'.$recharge->id.'-1',
            'status' => 'failed',
            'amount_minor' => Money::solesToMinor('10.00'),
            'currency' => 'PEN',
        ]);

        $secondAttempt = PaymentOrder::create([
            'recharge_request_id' => $recharge->id,
            'attempt_number' => 2,
            'provider' => 'mercadopago',
            'provider_order_id' => 'PAY-'.$recharge->id.'-2',
            'status' => 'pending',
            'amount_minor' => Money::solesToMinor('10.00'),
            'currency' => 'PEN',
        ]);

        $webhook = $this->webhookFor($secondAttempt, eventId: 'evt-attempt-2');
        $this->fakePaymentTruth($secondAttempt, $recharge, 'approved', 'accredited', transactionAmount: 10.0);

        $this->runJob($webhook);

        $this->assertSame('failed', $firstAttempt->fresh()->status);
        $this->assertSame('paid', $secondAttempt->fresh()->status);
        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "recharge:{$recharge->id}:deposit")->count());
    }

    // ---- discrepancias financieras: nunca acreditar, van a revisión ---------

    public function test_wrong_amount_never_credits_and_goes_to_review(): void
    {
        [$order, $recharge, $profile, $webhook] = $this->scenario(credits: 5, amountPen: '10.00');
        $this->fakePaymentTruth($order, $recharge, 'approved', 'accredited', transactionAmount: 15.0);

        $this->runJob($webhook);

        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertDatabaseCount('credit_transactions', 0);
        $this->assertSame('review', $webhook->fresh()->status);
    }

    public function test_one_cent_amount_mismatch_never_credits(): void
    {
        [$order, $recharge, $profile, $webhook] = $this->scenario(credits: 5, amountPen: '10.00');
        $this->fakePaymentTruth($order, $recharge, 'approved', 'accredited', transactionAmount: 9.99);

        $this->runJob($webhook);

        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertDatabaseCount('credit_transactions', 0);
        $this->assertSame('review', $webhook->fresh()->status);
    }

    public function test_malformed_amount_never_credits(): void
    {
        [$order, $recharge, $profile, $webhook] = $this->scenario(credits: 5, amountPen: '10.00');
        Http::fake([
            "api.mercadopago.com/v1/payments/{$order->provider_order_id}" => Http::response([
                'id' => $order->provider_order_id,
                'status' => 'approved',
                'status_detail' => 'accredited',
                'transaction_amount' => 'not-a-number',
                'external_reference' => "recharge:{$recharge->id}:attempt:1",
                'currency_id' => 'PEN',
            ], 200),
        ]);

        $this->runJob($webhook);

        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertDatabaseCount('credit_transactions', 0);
        $this->assertSame('review', $webhook->fresh()->status);
    }

    public function test_wrong_external_reference_never_credits(): void
    {
        [$order, $recharge, $profile, $webhook] = $this->scenario(credits: 5, amountPen: '10.00');
        Http::fake([
            "api.mercadopago.com/v1/payments/{$order->provider_order_id}" => Http::response([
                'id' => $order->provider_order_id,
                'status' => 'approved',
                'status_detail' => 'accredited',
                'transaction_amount' => 10.0,
                'external_reference' => 'recharge:OTRA-COSA:attempt:1',
                'currency_id' => 'PEN',
            ], 200),
        ]);

        $this->runJob($webhook);

        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertDatabaseCount('credit_transactions', 0);
        $this->assertSame('review', $webhook->fresh()->status);
    }

    public function test_wrong_currency_never_credits(): void
    {
        [$order, $recharge, $profile, $webhook] = $this->scenario(credits: 5, amountPen: '10.00');
        Http::fake([
            "api.mercadopago.com/v1/payments/{$order->provider_order_id}" => Http::response([
                'id' => $order->provider_order_id,
                'status' => 'approved',
                'status_detail' => 'accredited',
                'transaction_amount' => 10.0,
                'external_reference' => "recharge:{$recharge->id}:attempt:1",
                'currency_id' => 'ARS',
            ], 200),
        ]);

        $this->runJob($webhook);

        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertDatabaseCount('credit_transactions', 0);
        $this->assertSame('review', $webhook->fresh()->status);
    }

    public function test_missing_currency_in_response_goes_to_review_no_credit(): void
    {
        // FAIL CLOSED: currency_id debe existir Y ser exactamente PEN.
        [$order, $recharge, $profile, $webhook] = $this->scenario(credits: 5, amountPen: '10.00');
        Http::fake([
            "api.mercadopago.com/v1/payments/{$order->provider_order_id}" => Http::response([
                'id' => $order->provider_order_id,
                'status' => 'approved',
                'status_detail' => 'accredited',
                'transaction_amount' => 10.0,
                'external_reference' => "recharge:{$recharge->id}:attempt:1",
                // sin 'currency_id'
            ], 200),
        ]);

        $this->runJob($webhook);

        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertDatabaseCount('credit_transactions', 0);
        $this->assertSame('review', $webhook->fresh()->status);
    }

    public function test_never_uses_transaction_amount_refunded_as_the_expected_gross_amount(): void
    {
        // transaction_amount es el monto BRUTO — transaction_amount_refunded
        // es cuánto se devolvió, nunca lo que MOVA compara contra lo
        // esperado.
        [$order, $recharge, $profile, $webhook] = $this->scenario(credits: 5, amountPen: '10.00');
        Http::fake([
            "api.mercadopago.com/v1/payments/{$order->provider_order_id}" => Http::response([
                'id' => $order->provider_order_id,
                'status' => 'approved',
                'status_detail' => 'accredited',
                'transaction_amount' => 10.0,
                'transaction_amount_refunded' => 3.5,
                'external_reference' => "recharge:{$recharge->id}:attempt:1",
                'currency_id' => 'PEN',
            ], 200),
        ]);

        $this->runJob($webhook);

        $this->assertSame(5, $profile->fresh()->credits_available);
    }

    // ---- cuenta vendedora (collector_id) --------------------------------------

    public function test_wrong_collector_id_never_credits_and_goes_to_review(): void
    {
        config(['payments.mercadopago.expected_collector_id' => '470183340']);
        [$order, $recharge, $profile, $webhook] = $this->scenario(credits: 5, amountPen: '10.00');
        Http::fake([
            "api.mercadopago.com/v1/payments/{$order->provider_order_id}" => Http::response([
                'id' => $order->provider_order_id,
                'status' => 'approved',
                'status_detail' => 'accredited',
                'transaction_amount' => 10.0,
                'external_reference' => "recharge:{$recharge->id}:attempt:1",
                'currency_id' => 'PEN',
                'collector_id' => 111111111,
            ], 200),
        ]);

        $this->runJob($webhook);

        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertDatabaseCount('credit_transactions', 0);
        $this->assertSame('review', $webhook->fresh()->status);
    }

    public function test_matching_collector_id_credits_normally(): void
    {
        config(['payments.mercadopago.expected_collector_id' => '470183340']);
        [$order, $recharge, $profile, $webhook] = $this->scenario(credits: 5, amountPen: '10.00');
        $this->fakePaymentTruth($order, $recharge, 'approved', 'accredited', transactionAmount: 10.0, collectorId: 470183340);

        $this->runJob($webhook);

        $this->assertSame(5, $profile->fresh()->credits_available);
    }

    // ---- reversal / review ---------------------------------------------------

    public function test_full_refund_confirmed_reverses_exactly_once(): void
    {
        [$order, $recharge, $profile, $webhook] = $this->scenario(credits: 5, amountPen: '10.00');
        $refundWebhook = $this->webhookFor($order, eventId: 'evt-refund');
        $this->fakePaymentTruthSequence($order, $recharge, [
            ['approved', 'accredited', 10.0],
            ['refunded', 'refunded', 10.0],
        ]);

        $this->runJob($webhook); // paga primero
        $this->assertSame(5, $profile->fresh()->credits_available);

        $this->runJob($refundWebhook);

        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertSame('reversed', $recharge->fresh()->status);
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "recharge:{$recharge->id}:reversal")->count());
        $this->assertSame('processed', $refundWebhook->fresh()->status);
    }

    // ---- REFUND TRUTH: nunca por status/status_detail solamente -------------

    public function test_refunded_status_with_inconsistent_refunded_amount_goes_to_review_not_reverse(): void
    {
        // status="refunded"/status_detail="refunded" (el rótulo de reversal
        // total) pero transaction_amount_refunded NO coincide con el monto
        // original — nunca se revierte solo por el string de status.
        [$order, $recharge, $profile, $webhook] = $this->scenario(credits: 5, amountPen: '10.00');
        $refundWebhook = $this->webhookFor($order, eventId: 'evt-refund-inconsistent');
        $this->fakePaymentTruthSequence($order, $recharge, [
            ['approved', 'accredited', 10.0],
            ['refunded', 'refunded', 10.0, 7.5], // refunded != total
        ]);

        $this->runJob($webhook);
        $this->runJob($refundWebhook);

        $this->assertSame(5, $profile->fresh()->credits_available); // sin revertir
        $this->assertSame('approved', $recharge->fresh()->status);
        $this->assertDatabaseCount('credit_transactions', 1); // solo el deposit original
        $this->assertSame('review', $refundWebhook->fresh()->status);
    }

    public function test_refunded_status_with_zero_refunded_amount_goes_to_review(): void
    {
        [$order, $recharge, $profile, $webhook] = $this->scenario(credits: 5, amountPen: '10.00');
        $refundWebhook = $this->webhookFor($order, eventId: 'evt-refund-zero');
        $this->fakePaymentTruthSequence($order, $recharge, [
            ['approved', 'accredited', 10.0],
            ['refunded', 'refunded', 10.0, 0.0],
        ]);

        $this->runJob($webhook);
        $this->runJob($refundWebhook);

        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertSame('review', $refundWebhook->fresh()->status);
    }

    public function test_refunded_status_with_missing_refunded_amount_goes_to_review(): void
    {
        [$order, $recharge, $profile, $webhook] = $this->scenario(credits: 5, amountPen: '10.00');
        $refundWebhook = $this->webhookFor($order, eventId: 'evt-refund-missing');

        $sequence = Http::sequence()
            ->push($this->paymentTruthBody($order, $recharge, 'approved', 'accredited', 10.0), 200)
            ->push([
                'id' => $order->provider_order_id,
                'status' => 'refunded',
                'status_detail' => 'refunded',
                'transaction_amount' => 10.0,
                // sin 'transaction_amount_refunded'
                'external_reference' => "recharge:{$recharge->id}:attempt:1",
                'currency_id' => 'PEN',
            ], 200);
        Http::fake(["api.mercadopago.com/v1/payments/{$order->provider_order_id}" => $sequence]);

        $this->runJob($webhook);
        $this->runJob($refundWebhook);

        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertSame('review', $refundWebhook->fresh()->status);
    }

    public function test_repeated_full_refund_notification_produces_exactly_one_reversal(): void
    {
        [$order, $recharge, $profile, $webhook] = $this->scenario(credits: 5, amountPen: '10.00');
        $refundWebhook = $this->webhookFor($order, eventId: 'evt-refund-once');
        $secondRefundWebhook = $this->webhookFor($order, eventId: 'evt-refund-twice');
        // Los 3 fetches (pago, primer refund, segundo refund) van en UNA
        // sola secuencia — mezclar Http::fake() sueltos con una secuencia
        // ya usada para la misma URL puede volver a devolver la regla más
        // antigua en vez de la nueva (ver docblock de
        // fakePaymentTruthSequence()).
        $this->fakePaymentTruthSequence($order, $recharge, [
            ['approved', 'accredited', 10.0],
            ['refunded', 'refunded', 10.0],
            ['refunded', 'refunded', 10.0], // notificación DISTINTA, misma verdad
        ]);
        $this->runJob($webhook);
        $this->runJob($refundWebhook);
        $this->assertSame(0, $profile->fresh()->credits_available);

        $this->runJob($secondRefundWebhook);

        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "recharge:{$recharge->id}:reversal")->count());
    }

    // ---- REVIEW DURABILITY: persiste en PaymentOrder, no solo en un log -----

    public function test_review_reason_and_provider_status_persist_on_the_payment_order(): void
    {
        [$order, $recharge, $profile, $webhook] = $this->scenario(credits: 5, amountPen: '10.00');
        $this->fakePaymentTruth($order, $recharge, 'approved', 'accredited', transactionAmount: 15.0); // monto equivocado

        $this->runJob($webhook);

        $fresh = $order->fresh();
        $this->assertNotNull($fresh->review_reason);
        $this->assertSame('approved', $fresh->provider_status);
        $this->assertSame('accredited', $fresh->provider_status_detail);
        $this->assertNotNull($fresh->last_verified_at);
        // Navegable sin grepear logs: RechargeRequest → attempt → provider
        // payment id → review_reason.
        $this->assertSame($recharge->id, $fresh->recharge_request_id);
        $this->assertNotNull($fresh->provider_order_id);
    }

    public function test_review_reason_clears_once_a_later_reconciliation_resolves_cleanly(): void
    {
        [$order, $recharge, $profile, $webhook] = $this->scenario(credits: 5, amountPen: '10.00');
        $secondWebhook = $this->webhookFor($order, eventId: 'evt-corrected');
        // Http::sequence(): primer fetch con monto equivocado, segundo
        // fetch (de la segunda notificación) con el monto ya corregido —
        // dos Http::fake() sueltos para la misma URL no se reemplazan de
        // forma confiable (ver docblock de fakePaymentTruthSequence()).
        $this->fakePaymentTruthSequence($order, $recharge, [
            ['approved', 'accredited', 15.0], // monto equivocado
            ['approved', 'accredited', 10.0], // corregido
        ]);

        $this->runJob($webhook);
        $this->assertNotNull($order->fresh()->review_reason);

        $this->runJob($secondWebhook);

        $this->assertNull($order->fresh()->review_reason); // ya no hay anomalía ACTUAL
        $this->assertSame(5, $profile->fresh()->credits_available);
    }

    /**
     * REVIEW REOPEN (ronda del pre-commit gate): episodio #1 (monto
     * equivocado) → se resuelve (monto corregido, acredita) →
     * posteriormente una anomalía DISTINTA (reembolso parcial) abre el
     * episodio #2. Mientras el episodio #2 está activo: review_reason es la
     * razón NUEVA (no la del episodio #1), review_detected_at representa
     * el inicio del episodio ACTUAL (posterior al de episodio #1, nunca
     * reutilizado), y review_resolved_at vuelve a null. No hace falta
     * guardar historial ilimitado — solo el episodio activo actual.
     */
    public function test_a_second_distinct_anomaly_opens_a_new_review_episode_after_the_first_one_resolved(): void
    {
        [$order, $recharge, $profile, $webhook] = $this->scenario(credits: 5, amountPen: '10.00');
        $resolvedWebhook = $this->webhookFor($order, eventId: 'evt-resolved');
        $partialRefundWebhook = $this->webhookFor($order, eventId: 'evt-partial-refund');

        $this->fakePaymentTruthSequence($order, $recharge, [
            ['approved', 'accredited', 15.0],                  // episodio #1: monto equivocado
            ['approved', 'accredited', 10.0],                  // resuelto: monto corregido
            ['refunded', 'refunded', 10.0, 5.0],                // episodio #2: reembolso PARCIAL (razón distinta)
        ]);

        // Episodio #1: activo.
        $this->runJob($webhook);
        $episode1 = $order->fresh();
        $this->assertNotNull($episode1->review_reason);
        $episode1Reason = $episode1->review_reason;
        $this->assertNotNull($episode1->review_detected_at);
        $this->assertNull($episode1->review_resolved_at);
        $episode1DetectedAt = $episode1->review_detected_at;

        // Resuelto: monto corregido, acredita, review_resolved_at se fija.
        $this->runJob($resolvedWebhook);
        $resolved = $order->fresh();
        $this->assertNull($resolved->review_reason);
        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertTrue($resolved->review_detected_at->equalTo($episode1DetectedAt)); // preservado
        $this->assertNotNull($resolved->review_resolved_at);

        // Episodio #2: anomalía DISTINTA (reembolso parcial, no monto).
        $this->runJob($partialRefundWebhook);
        $episode2 = $order->fresh();
        $this->assertNotNull($episode2->review_reason);
        $this->assertNotSame($episode1Reason, $episode2->review_reason); // razón NUEVA
        $this->assertStringContainsString('PARCIAL', $episode2->review_reason);
        $this->assertNotNull($episode2->review_detected_at);
        $this->assertTrue($episode2->review_detected_at->greaterThanOrEqualTo($episode1DetectedAt)); // episodio ACTUAL
        $this->assertNull($episode2->review_resolved_at); // activo de nuevo
        // Ningún reversal automático por el episodio #2 (no confirmado un
        // reembolso total) — el crédito del episodio #1 se mantiene.
        $this->assertSame(5, $profile->fresh()->credits_available);
    }

    public function test_refund_confirmed_on_a_never_approved_recharge_goes_to_review_not_reverse(): void
    {
        [$order, $recharge, $profile, $webhook] = $this->scenario(credits: 5, amountPen: '10.00');
        $this->fakePaymentTruth($order, $recharge, 'refunded', 'refunded', transactionAmount: 10.0);

        $this->runJob($webhook);

        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertSame('pending', $recharge->fresh()->status);
        $this->assertDatabaseCount('credit_transactions', 0);
        $this->assertSame('review', $webhook->fresh()->status);
    }

    public function test_partial_refund_goes_to_review_never_automatic_full_reversal(): void
    {
        [$order, $recharge, $profile, $webhook] = $this->scenario(credits: 5, amountPen: '10.00');
        $partialWebhook = $this->webhookFor($order, eventId: 'evt-partial');
        $this->fakePaymentTruthSequence($order, $recharge, [
            ['approved', 'accredited', 10.0],
            ['approved', 'partially_refunded', 10.0],
        ]);

        $this->runJob($webhook);
        $this->runJob($partialWebhook);

        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertSame('approved', $recharge->fresh()->status);
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "recharge:{$recharge->id}:deposit")->count());
        $this->assertDatabaseCount('credit_transactions', 1);
        $this->assertSame('review', $partialWebhook->fresh()->status);
    }

    public function test_ambiguous_chargeback_goes_to_review(): void
    {
        [$order, $recharge, $profile, $webhook] = $this->scenario(credits: 5, amountPen: '10.00');
        $chargebackWebhook = $this->webhookFor($order, eventId: 'evt-cb');
        $this->fakePaymentTruthSequence($order, $recharge, [
            ['approved', 'accredited', 10.0],
            ['charged_back', 'settled', 10.0],
        ]);

        $this->runJob($webhook);
        $this->runJob($chargebackWebhook);

        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertDatabaseCount('credit_transactions', 1);
        $this->assertSame('review', $chargebackWebhook->fresh()->status);
    }

    // ---- resiliencia / fallos de infraestructura -----------------------------

    public function test_missing_local_payment_order_throws_for_retry(): void
    {
        $webhook = PaymentWebhook::create([
            'provider' => 'mercadopago',
            'event_id' => 'evt-orphan',
            'event_type' => 'payment.updated',
            'payload' => ['id' => 'evt-orphan', 'data' => ['id' => 'PAY-NO-EXISTE']],
            'payload_hash' => hash('sha256', 'x'),
            'received_at' => now(),
            'status' => 'received',
        ]);

        $this->expectException(RuntimeException::class);
        $this->runJob($webhook);
    }

    public function test_fetch_payment_failure_throws_for_retry_without_crediting(): void
    {
        [$order, , $profile, $webhook] = $this->scenario();
        Http::fake(["api.mercadopago.com/v1/payments/{$order->provider_order_id}" => Http::response(['message' => 'down'], 500)]);

        $this->expectException(RuntimeException::class);

        try {
            $this->runJob($webhook);
        } finally {
            $this->assertSame(0, $profile->fresh()->credits_available);
            $this->assertSame('received', $webhook->fresh()->status);
        }
    }

    public function test_already_processed_webhook_is_not_reprocessed(): void
    {
        [$order, $recharge, $profile, $webhook] = $this->scenario(credits: 5, amountPen: '10.00');
        $this->fakePaymentTruth($order, $recharge, 'approved', 'accredited', transactionAmount: 10.0);
        $this->runJob($webhook);
        $this->assertSame(5, $profile->fresh()->credits_available);

        Http::fake(); // cualquier llamada aquí sería un bug
        $this->runJob($webhook);

        $this->assertSame(5, $profile->fresh()->credits_available);
        Http::assertNothingSent();
    }

    // ---- PROCESSING: dos ejecuciones solapadas del MISMO webhook aún ---------
    // 'received' (el schema no tiene un estado 'processing' intermedio — ver
    // migración create_payment_webhooks_table; el job nunca marca nada
    // mientras corre) ----------------------------------------------------------

    /**
     * Un segundo "worker" reentrante — disparado desde DENTRO del propio
     * closure de Http::fake() que responde el GET del primero, mismo patrón
     * ya usado en MercadoPagoLostChallengeCompensationTest para
     * compensateLostChallenge() — corre su handle() COMPLETO (incluida su
     * propia GET + reconcile()) antes de que el primer worker llegue a abrir
     * su propia transacción/lockForUpdate() sobre la PaymentOrder. Prueba
     * que la garantía real de exactamente-once bajo procesamiento
     * concurrente del MISMO evento es el lock de fila de PaymentOrder +
     * applyPaid() idempotente — NUNCA el status de payment_webhooks (que ni
     * siquiera cambia entre los dos workers hasta que ambos terminan).
     */
    public function test_reentrant_processing_of_the_same_still_received_webhook_never_double_credits(): void
    {
        [$order, $recharge, $profile, $webhook] = $this->scenario(credits: 5, amountPen: '10.00');

        $reentered = false;
        Http::fake([
            "api.mercadopago.com/v1/payments/{$order->provider_order_id}" => function () use (&$reentered, $webhook, $order) {
                if (! $reentered) {
                    $reentered = true;
                    // El segundo worker ve el MISMO payment_webhook, todavía
                    // 'received' (nadie lo marcó "processing" — no existe
                    // ese estado) — corre a completitud, incluida su propia
                    // llamada a fetchPayment() (que reentra en este mismo
                    // closure con $reentered ya en true, sin recursión
                    // infinita).
                    $this->runJob($webhook->fresh());
                }

                return Http::response([
                    'id' => $order->provider_order_id,
                    'status' => 'approved',
                    'status_detail' => 'accredited',
                    'transaction_amount' => 10.0,
                    'transaction_amount_refunded' => 0,
                    'external_reference' => $order->externalReference(),
                    'currency_id' => 'PEN',
                ], 200);
            },
        ]);

        $this->runJob($webhook);

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(5, $profile->fresh()->credits_available); // nunca 10
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "recharge:{$recharge->id}:deposit")->count());
        $this->assertSame('processed', $webhook->fresh()->status);
    }

    // ---- helpers --------------------------------------------------------------

    private function runJob(PaymentWebhook $webhook): void
    {
        (new ProcessMercadoPagoWebhook($webhook->id))->handle(
            app(MercadoPagoPaymentProvider::class),
            app(MercadoPagoPaymentReconciliationService::class),
        );
    }

    /**
     * @return array{0:PaymentOrder,1:RechargeRequest,2:TeacherProfile,3:PaymentWebhook}
     */
    private function scenario(int $credits = 5, string $amountPen = '10.00'): array
    {
        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile, credits: $credits, amountPen: $amountPen);

        $order = PaymentOrder::create([
            'recharge_request_id' => $recharge->id,
            'attempt_number' => 1,
            'provider' => 'mercadopago',
            'provider_order_id' => 'PAY-'.$recharge->id,
            'status' => 'pending',
            'amount_minor' => Money::solesToMinor($amountPen),
            'currency' => 'PEN',
        ]);

        $webhook = $this->webhookFor($order, eventId: 'evt-'.$recharge->id);

        return [$order, $recharge, $profile, $webhook];
    }

    private function teacher(): array
    {
        $teacher = User::factory()->create(['password' => 'password']);
        $teacher->assignRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_verified' => true,
            'credits_available' => 0,
            'credits_reserved' => 0,
        ]);

        return [$teacher, $profile];
    }

    private function recharge(TeacherProfile $profile, int $credits = 5, string $amountPen = '10.00'): RechargeRequest
    {
        $operation = fake()->unique()->numerify('OP########');

        return RechargeRequest::create([
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
    }

    private function webhookFor(PaymentOrder $order, string $eventId): PaymentWebhook
    {
        return PaymentWebhook::create([
            'provider' => 'mercadopago',
            'event_id' => $eventId,
            'event_type' => 'payment.updated',
            'payload' => ['id' => $eventId, 'data' => ['id' => $order->provider_order_id]],
            'payload_hash' => hash('sha256', $eventId),
            'received_at' => now(),
            'status' => 'received',
        ]);
    }

    /**
     * Respuesta fija (no Http::sequence()) — se repite igual ante cualquier
     * cantidad de llamadas a esta URL.
     */
    private function fakePaymentTruth(
        PaymentOrder $order,
        RechargeRequest $recharge,
        string $status,
        ?string $statusDetail,
        float $transactionAmount = 10.0,
        ?int $collectorId = null,
        string $paymentMethodId = 'visa',
        ?float $transactionAmountRefunded = null
    ): void {
        Http::fake([
            "api.mercadopago.com/v1/payments/{$order->provider_order_id}" => Http::response(
                $this->paymentTruthBody($order, $recharge, $status, $statusDetail, $transactionAmount, $collectorId, $paymentMethodId, $transactionAmountRefunded),
                200
            ),
        ]);
    }

    /**
     * Igual razón que en la ronda anterior con Http::sequence(): un test
     * que corre el job dos veces sobre el MISMO pago esperando respuestas
     * DISTINTAS necesita una secuencia, no dos Http::fake() sueltos (la
     * regla anterior puede seguir aplicando).
     *
     * @param  array<int,array{0:string,1:?string,2:float,3?:?float}>  $steps  [status, status_detail, transaction_amount, transaction_amount_refunded?]
     */
    private function fakePaymentTruthSequence(PaymentOrder $order, RechargeRequest $recharge, array $steps): void
    {
        $sequence = Http::sequence();

        foreach ($steps as $step) {
            [$status, $statusDetail, $transactionAmount] = $step;
            $transactionAmountRefunded = $step[3] ?? null;
            $sequence->push($this->paymentTruthBody($order, $recharge, $status, $statusDetail, $transactionAmount, null, 'visa', $transactionAmountRefunded), 200);
        }

        Http::fake(["api.mercadopago.com/v1/payments/{$order->provider_order_id}" => $sequence]);
    }

    /**
     * REFUND TRUTH: transaction_amount_refunded nunca queda "ausente" salvo
     * que el test lo pida explícitamente (null) — por default, si el
     * status/status_detail mapea a un reversal confirmado
     * (refunded/refunded o refunded/by_admin), se completa automáticamente
     * con el monto TOTAL (reembolso completo, el caso feliz); para
     * cualquier otro status se completa con 0. Los tests que quieren
     * probar específicamente un reembolso parcial/ausente/inconsistente lo
     * pasan de forma explícita.
     */
    private function paymentTruthBody(
        PaymentOrder $order,
        RechargeRequest $recharge,
        string $status,
        ?string $statusDetail,
        float $transactionAmount,
        ?int $collectorId = null,
        string $paymentMethodId = 'visa',
        ?float $transactionAmountRefunded = null
    ): array {
        if ($transactionAmountRefunded === null) {
            $transactionAmountRefunded = ($status === 'refunded' && in_array($statusDetail, ['refunded', 'by_admin'], true))
                ? $transactionAmount
                : 0.0;
        }

        return [
            'id' => $order->provider_order_id,
            'status' => $status,
            'status_detail' => $statusDetail,
            'transaction_amount' => $transactionAmount,
            'transaction_amount_refunded' => $transactionAmountRefunded,
            'external_reference' => "recharge:{$recharge->id}:attempt:{$order->attempt_number}",
            'currency_id' => 'PEN',
            'collector_id' => $collectorId,
            'payment_method_id' => $paymentMethodId,
            'payment_type_id' => $paymentMethodId === 'yape' ? 'debit_card' : 'credit_card',
        ];
    }
}
