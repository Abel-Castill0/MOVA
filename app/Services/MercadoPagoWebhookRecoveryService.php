<?php

namespace App\Services;

use App\Jobs\ProcessMercadoPagoWebhook;
use App\Models\PaymentOrder;
use App\Models\PaymentWebhook;
use App\Payment\MercadoPagoPaymentProvider;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recuperación durable e idempotente — reutiliza ProcessMercadoPagoWebhook
 * y MercadoPagoPaymentReconciliationService, los mismos que ya procesan el
 * camino normal, en vez de crear un subsistema nuevo. Cuatro pasadas
 * acotadas (nunca table scan infinito — todas usan una ventana de tiempo y
 * un límite de lote configurables, ver config/payments.php
 * 'mercadopago.recovery', nunca un número mágico en este archivo):
 *
 *   1. payment_webhooks en 'received' hace demasiado tiempo — el job nunca
 *      llegó a correr, o el worker murió a mitad de proceso.
 *   2. payment_webhooks en 'failed' — Laravel agotó los $tries del job.
 *      Se resetean a 'received' y se reencolan HASTA un tope de
 *      reintentos DEL RECOVERY (recovery_attempts, distinto de $tries del
 *      job) — superado el tope, caen a 'review' en vez de reencolarse para
 *      siempre (evita un loop received→failed→received infinito y
 *      silencioso).
 *   3. PaymentOrder locales que siguen no-terminales (pending) más allá de
 *      un umbral — cubre una notificación que Mercado Pago JAMÁS entregó.
 *      Se reconcilian DIRECTAMENTE, sin depender de payment_webhooks.
 *   4. PaymentOrder ya 'paid' dentro de una ventana ACOTADA (lookback) —
 *      detecta un refund confirmado cuyo webhook nunca llegó. Nunca revisa
 *      pagos fuera de la ventana ni pagos muy recientes (todavía dentro de
 *      la latencia normal de entrega de un webhook).
 *   5. PaymentOrder con `submission_status` en `submitting`/`uncertain`
 *      (POST /v1/payments sin respuesta definitiva, o el proceso murió
 *      justo antes/durante el envío — ver SUBMISSION LIFECYCLE en
 *      MercadoPagoPaymentProvider) más viejas que un umbral mínimo —
 *      recuperación DIRIGIDA por
 *      external_reference vía
 *      MercadoPagoPaymentReconciliationService::reconcileUncertainSubmission(),
 *      el MISMO método que usa el gate síncrono dentro de
 *      MercadoPagoPaymentProvider::createPaymentAttempt(). Presupuesto
 *      propio (`payment_orders.recovery_attempts`, ver migración
 *      2026_09_01_000006) — nunca reintenta para siempre.
 *
 * Nunca duplica créditos/reversals: todo pasa por
 * MercadoPagoPaymentReconciliationService, que relee la verdad
 * server-to-server y aplica la misma lógica idempotente que el camino
 * normal.
 *
 * EVALUADO (ronda de hardening final) y descartado por ahora: Payments API
 * ofrece `GET /v1/payments/search?range=date_last_updated&begin_date=...
 * &end_date=...` con paginación — un mecanismo oficial de reconciliación
 * INCREMENTAL (pedirle a Mercado Pago "qué cambió", en vez de que MOVA
 * revise cada `payment_order` local una por una). Es una arquitectura
 * genuinamente mejor a mayor escala, pero implementarla ahora sería una
 * segunda arquitectura de recovery antes de que la primera haya corrido en
 * producción ni una sola vez. Decisión: el lookback local (pasada 4, ahora
 * 90 días por defecto — el horizonte de reembolso documentado de Mercado
 * Pago) sobre una consulta indexada por rango (`paid_at`, ver migración
 * 2026_09_01_000005) es suficiente como primera versión productiva — sigue
 * siendo una consulta ACOTADA, nunca un table scan, incluso a 90 días,
 * porque el volumen de `payment_orders.status='paid'` de MOVA es pequeño en
 * esta etapa. `/v1/payments/search` queda registrado como la vía natural de
 * evolución si el volumen creciera lo suficiente para que el barrido local
 * se vuelva costoso — no se implementa esta ronda.
 */
class MercadoPagoWebhookRecoveryService
{
    public function __construct(
        private readonly MercadoPagoPaymentProvider $provider,
        private readonly MercadoPagoPaymentReconciliationService $reconciler,
    ) {
    }

    /**
     * @return array{
     *   stale_received:int,failed_requeued:int,failed_exhausted_to_review:int,
     *   stuck_orders_reconciled:int,stuck_orders_errored:int,
     *   paid_lookback_reconciled:int,paid_lookback_errored:int,
     *   uncertain_resolved:int,uncertain_still_uncertain:int,
     *   uncertain_exhausted:int,uncertain_ambiguous:int,uncertain_search_failed:int
     * }
     */
    public function recover(
        ?int $staleReceivedMinutes = null,
        ?int $stuckOrderMinutes = null,
        ?int $paidLookbackDays = null,
        ?int $paidLookbackMinAgeMinutes = null,
        ?int $maxRecoveryAttempts = null,
        ?int $batchSize = null,
        ?int $uncertainMinAgeMinutes = null,
        ?int $uncertainMaxAttempts = null,
    ): array {
        $staleReceivedMinutes ??= (int) config('payments.mercadopago.recovery.stale_received_minutes', 15);
        $stuckOrderMinutes ??= (int) config('payments.mercadopago.recovery.stuck_order_minutes', 30);
        $paidLookbackDays ??= (int) config('payments.mercadopago.recovery.paid_lookback_days', 7);
        $paidLookbackMinAgeMinutes ??= (int) config('payments.mercadopago.recovery.paid_lookback_min_age_minutes', 60);
        $maxRecoveryAttempts ??= (int) config('payments.mercadopago.recovery.max_recovery_attempts', 3);
        $batchSize ??= (int) config('payments.mercadopago.recovery.batch_size', 200);
        $uncertainMinAgeMinutes ??= (int) config('payments.mercadopago.recovery.uncertain_search_min_age_minutes', 5);
        $uncertainMaxAttempts ??= (int) config('payments.mercadopago.recovery.uncertain_search_max_attempts', 5);

        $result = [
            'stale_received' => 0,
            'failed_requeued' => 0,
            'failed_exhausted_to_review' => 0,
            'stuck_orders_reconciled' => 0,
            'stuck_orders_errored' => 0,
            'paid_lookback_reconciled' => 0,
            'paid_lookback_errored' => 0,
            'uncertain_resolved' => 0,
            'uncertain_still_uncertain' => 0,
            'uncertain_exhausted' => 0,
            'uncertain_ambiguous' => 0,
            'uncertain_search_failed' => 0,
        ];

        $this->requeueStaleReceived($staleReceivedMinutes, $batchSize, $result);
        $this->requeueOrExhaustFailed($maxRecoveryAttempts, $batchSize, $result);
        $this->reconcileStuckOrders($stuckOrderMinutes, $batchSize, $result);
        $this->reconcilePaidLookback($paidLookbackDays, $paidLookbackMinAgeMinutes, $batchSize, $result);
        $this->reconcileUncertainSubmissions($uncertainMinAgeMinutes, $uncertainMaxAttempts, $batchSize, $result);

        return $result;
    }

    private function requeueStaleReceived(int $staleReceivedMinutes, int $batchSize, array &$result): void
    {
        PaymentWebhook::query()
            ->where('provider', 'mercadopago')
            ->where('status', 'received')
            ->where('received_at', '<=', now()->subMinutes($staleReceivedMinutes))
            ->limit($batchSize)
            ->get()
            ->each(function (PaymentWebhook $webhook) use (&$result) {
                Log::info('[MercadoPago/Recovery] Reencolando payment_webhook atascado en "received".', [
                    'payment_webhook_id' => $webhook->id,
                ]);
                ProcessMercadoPagoWebhook::dispatch($webhook->id);
                $result['stale_received']++;
            });
    }

    private function requeueOrExhaustFailed(int $maxRecoveryAttempts, int $batchSize, array &$result): void
    {
        PaymentWebhook::query()
            ->where('provider', 'mercadopago')
            ->where('status', 'failed')
            ->limit($batchSize)
            ->get()
            ->each(function (PaymentWebhook $webhook) use ($maxRecoveryAttempts, &$result) {
                if ($webhook->recovery_attempts >= $maxRecoveryAttempts) {
                    // Retry budget agotado — la causa probablemente NO es
                    // transitoria (un MP_API_DOWN pasajero ya se habría
                    // resuelto en $maxRecoveryAttempts barridos). Dead
                    // letter mínimo: reutiliza 'review' (sin tabla nueva),
                    // deja de reencolar para siempre.
                    $reason = 'Reintentos de recuperación agotados ('.$webhook->recovery_attempts.') — requiere revisión manual.';
                    $webhook->update(['status' => 'review', 'error' => $reason]);

                    // REVIEW DURABILITY: si el payload de la notificación
                    // permite identificar la PaymentOrder (data.id), deja
                    // constancia también ahí — no solo en payment_webhooks
                    // — para que el operador la encuentre navegando desde
                    // RechargeRequest, no solo grepeando payment_webhooks.
                    $providerOrderId = $webhook->payload['data']['id'] ?? null;
                    if (is_string($providerOrderId) && $providerOrderId !== '') {
                        PaymentOrder::where('provider', 'mercadopago')
                            ->where('provider_order_id', $providerOrderId)
                            ->first()
                            ?->update(['review_reason' => substr($reason, 0, 500), 'last_verified_at' => now()]);
                    }

                    Log::warning('[MercadoPago/Recovery] Retry budget agotado — payment_webhook movido a "review".', [
                        'payment_webhook_id' => $webhook->id,
                        'recovery_attempts' => $webhook->recovery_attempts,
                    ]);
                    $result['failed_exhausted_to_review']++;

                    return;
                }

                $webhook->update([
                    'status' => 'received',
                    'error' => null,
                    'recovery_attempts' => $webhook->recovery_attempts + 1,
                ]);
                Log::info('[MercadoPago/Recovery] Reintentando payment_webhook que había agotado sus reintentos.', [
                    'payment_webhook_id' => $webhook->id,
                    'recovery_attempts' => $webhook->recovery_attempts,
                ]);
                ProcessMercadoPagoWebhook::dispatch($webhook->id);
                $result['failed_requeued']++;
            });
    }

    private function reconcileStuckOrders(int $stuckOrderMinutes, int $batchSize, array &$result): void
    {
        PaymentOrder::query()
            ->where('provider', 'mercadopago')
            ->where('status', 'pending')
            // Excluye intentos que NUNCA recibieron respuesta de Mercado
            // Pago (provider_order_id NULL — ver
            // MercadoPagoPaymentProvider::resolveAttemptRow()): no hay
            // ningún `id` que consultar vía fetchPayment(), y MOVA no puede
            // reintentar el POST por sí sola (el token es de un solo uso y
            // nunca se persiste) — solo un nuevo intento del profesor puede
            // resolverlos. Sin este filtro, reconcile() los marcaría
            // 'errored' en CADA barrido para siempre, sin señal accionable.
            ->whereNotNull('provider_order_id')
            ->where('updated_at', '<=', now()->subMinutes($stuckOrderMinutes))
            ->limit($batchSize)
            ->get()
            ->each(function (PaymentOrder $order) use (&$result) {
                try {
                    $outcome = $this->reconciler->reconcile($order, null, $this->provider);
                    Log::info('[MercadoPago/Recovery] Intento de pago atascado reconciliado directamente.', [
                        'payment_order_id' => $order->id,
                        'outcome' => $outcome,
                    ]);
                    $result['stuck_orders_reconciled']++;
                } catch (Throwable $e) {
                    Log::warning('[MercadoPago/Recovery] No se pudo reconciliar intento atascado — se reintentará en el próximo barrido.', [
                        'payment_order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                    $result['stuck_orders_errored']++;
                }
            });
    }

    /**
     * Ventana acotada: solo pagos 'paid' entre hace $paidLookbackDays días
     * y hace $paidLookbackMinAgeMinutes minutos. Nunca "todos los pagos
     * pagados alguna vez" — eso sí sería un table scan sin fin a medida que
     * crece el historial.
     */
    private function reconcilePaidLookback(int $paidLookbackDays, int $paidLookbackMinAgeMinutes, int $batchSize, array &$result): void
    {
        PaymentOrder::query()
            ->where('provider', 'mercadopago')
            ->where('status', 'paid')
            ->whereBetween('paid_at', [now()->subDays($paidLookbackDays), now()->subMinutes($paidLookbackMinAgeMinutes)])
            ->limit($batchSize)
            ->get()
            ->each(function (PaymentOrder $order) use (&$result) {
                try {
                    $outcome = $this->reconciler->reconcile($order, null, $this->provider);
                    if ($outcome !== 'paid') {
                        // El caso que esta pasada existe para encontrar: la
                        // verdad remota cambió (ej. 'reversed') sin que
                        // ningún webhook lo avisara.
                        Log::info('[MercadoPago/Recovery] Pago dentro del lookback cambió de estado — reconciliado.', [
                            'payment_order_id' => $order->id,
                            'outcome' => $outcome,
                        ]);
                    }
                    $result['paid_lookback_reconciled']++;
                } catch (Throwable $e) {
                    Log::warning('[MercadoPago/Recovery] No se pudo reconciliar pago dentro del lookback — se reintentará en el próximo barrido.', [
                        'payment_order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                    $result['paid_lookback_errored']++;
                }
            });
    }

    /**
     * UNKNOWN PAYMENT RECOVERY — barrido en LOTE, acotado por edad mínima
     * (`uncertainMinAgeMinutes`, le da tiempo a Mercado Pago de indexar el
     * pago si de verdad se creó) y tamaño de lote, sobre intentos con
     * `submission_status` en `submitting`/`uncertain` (cubre tanto un POST
     * que sí corrió y quedó ambiguo, como un proceso que murió justo antes
     * de completarlo). Delega TODA la decisión en
     * MercadoPagoPaymentReconciliationService::reconcileUncertainSubmission()
     * — el mismo método que usa el gate síncrono de
     * MercadoPagoPaymentProvider::createPaymentAttempt() — así que esta
     * pasada nunca duplica lógica de búsqueda/validación/crédito, solo
     * decide QUÉ filas visitar.
     */
    private function reconcileUncertainSubmissions(int $uncertainMinAgeMinutes, int $uncertainMaxAttempts, int $batchSize, array &$result): void
    {
        PaymentOrder::query()
            ->where('provider', 'mercadopago')
            ->whereIn('submission_status', ['submitting', 'uncertain'])
            ->whereNull('provider_order_id')
            ->where('updated_at', '<=', now()->subMinutes($uncertainMinAgeMinutes))
            ->limit($batchSize)
            ->get()
            ->each(function (PaymentOrder $order) use ($uncertainMaxAttempts, &$result) {
                $outcome = $this->reconciler->reconcileUncertainSubmission($order, $this->provider, $uncertainMaxAttempts);

                match ($outcome) {
                    'resolved' => $result['uncertain_resolved']++,
                    'still_uncertain' => $result['uncertain_still_uncertain']++,
                    'exhausted' => $result['uncertain_exhausted']++,
                    'ambiguous' => $result['uncertain_ambiguous']++,
                    'search_failed' => $result['uncertain_search_failed']++,
                    default => null,
                };

                Log::info('[MercadoPago/Recovery] Intento incierto procesado.', [
                    'payment_order_id' => $order->id,
                    'outcome' => $outcome,
                ]);
            });
    }
}
