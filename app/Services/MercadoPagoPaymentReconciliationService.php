<?php

namespace App\Services;

use App\Models\PaymentOrder;
use App\Models\PaymentWebhook;
use App\Models\RechargeRequest;
use App\Payment\MercadoPago\MercadoPagoPaymentStatusMapper;
use App\Payment\MercadoPagoPaymentProvider;
use App\Payment\Money;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Única pieza que decide la VERDAD financiera de un pago de Mercado Pago
 * Payments API: relee el pago server-to-server (fetchPayment(), nunca
 * confía en el payload de un webhook ni en la respuesta síncrona de
 * createPaymentAttempt()), valida monto/referencia/moneda/cuenta contra lo
 * que MOVA ya congeló en PaymentOrder al crear el intento, normaliza el
 * estado con MercadoPagoPaymentStatusMapper, confirma el MONTO reembolsado
 * antes de tratar algo como reversal, y solo entonces delega en
 * RechargeApprovalService::credit()/reverse().
 *
 * REVIEW DURABILITY (ronda de hardening final): toda anomalía financiera
 * (contexto no coincide, estado ambiguo, refund inconsistente) se persiste
 * en la PROPIA PaymentOrder — `review_reason`/`provider_status`/
 * `provider_status_detail`/`last_verified_at` — no solo en
 * payment_webhooks (que puede no existir: MercadoPagoWebhookRecoveryService
 * reconcilia orders huérfanas con $webhook=null) ni en un log. Un operador
 * navega RechargeRequest → PaymentOrder (attempt_number, provider_order_id)
 * → review_reason sin grepear nada. `review_reason` se limpia (null) en
 * cuanto una reconciliación posterior resuelve el intento a un estado no
 * ambiguo — refleja siempre el estado ACTUAL, no un historial.
 * `review_detected_at`/`review_resolved_at` sí guardan cuándo empezó/terminó
 * el episodio de revisión más reciente (ver markReview()/recordResolvedTruth()).
 *
 * UNKNOWN PAYMENT RECOVERY (ronda de hardening distribuido):
 * reconcileUncertainSubmission() resuelve intentos cuyo POST
 * /v1/payments nunca dio una respuesta definitiva (timeout/5xx/429/2xx sin
 * id/conflicto de idempotencia — ver
 * MercadoPagoPaymentProvider::classifyHttpFailure()) vía búsqueda DIRIGIDA
 * por external_reference — nunca confía en la respuesta síncrona ni en un
 * segundo camino de crédito.
 */
class MercadoPagoPaymentReconciliationService
{
    /**
     * @return string  outcome: 'paid'|'pending'|'failed'|'reversed'|'review'
     */
    public function reconcile(PaymentOrder $order, ?PaymentWebhook $webhook, MercadoPagoPaymentProvider $provider): string
    {
        $paymentId = $order->provider_order_id;

        if ($paymentId === null) {
            // Intento local todavía sin confirmar por Mercado Pago (ver
            // MercadoPagoPaymentProvider::resolveAttemptRow()) — no hay
            // nada que reconciliar server-to-server todavía; reintentable
            // (un create/retry posterior lo resolverá), nunca 'review'.
            throw new RuntimeException("MercadoPagoPaymentReconciliationService: PaymentOrder#{$order->id} todavía no tiene provider_order_id — nada que consultar.");
        }

        $truth = $provider->fetchPayment($paymentId);
        if ($truth === null) {
            throw new RuntimeException("MercadoPagoPaymentReconciliationService: fetchPayment falló para payment {$paymentId}.");
        }

        if ($truth['id'] !== $paymentId) {
            $this->markReview($order, $webhook, 'fetchPayment devolvió un id distinto al consultado.', $truth);

            return 'review';
        }

        return DB::transaction(function () use ($order, $truth, $webhook) {
            $order = PaymentOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            try {
                $recharge = RechargeRequest::whereKey($order->recharge_request_id)->lockForUpdate()->firstOrFail();
            } catch (ModelNotFoundException $e) {
                throw new RuntimeException('RechargeRequest asociada no existe — inconsistencia de datos.', previous: $e);
            }

            if (! $this->truthMatchesExpectedContext($truth, $order, $recharge)) {
                $this->markReview($order, $webhook, 'external_reference/amount/currency/cuenta de Mercado Pago no coincide con lo esperado — posible pago equivocado, no se acredita.', $truth);

                Log::error('[MercadoPago/Reconcile] Discrepancia financiera o de contexto — fail closed, marcado para revisión.', [
                    'payment_webhook_id' => $webhook?->id,
                    'payment_order_id' => $order->id,
                    'recharge_request_id' => $recharge->id,
                    'attempt_number' => $order->attempt_number,
                    'expected_amount_minor' => $order->amount_minor,
                    'expected_external_reference' => "recharge:{$recharge->id}:attempt:{$order->attempt_number}",
                    'reported_transaction_amount' => $truth['transaction_amount'],
                    'reported_external_reference' => $truth['external_reference'],
                    'reported_currency_id' => $truth['currency_id'],
                    'reported_collector_id' => $truth['collector_id'],
                ]);

                return 'review';
            }

            $normalized = MercadoPagoPaymentStatusMapper::normalize($truth['status'], $truth['status_detail']);

            if ($normalized === 'reversed' && ! $this->refundAmountConfirmsFullReversal($truth, $order)) {
                // REFUND TRUTH: el status/status_detail solo es un ROTULO —
                // nunca se revierte solo por eso. Exige
                // transaction_amount_refunded == transaction_amount,
                // normalizado a centavos enteros e igual al monto congelado
                // en el intento. Ausente/inconsistente/parcial → review.
                $this->markReview($order, $webhook, $this->refundMismatchReason($truth, $order), $truth);

                Log::error('[MercadoPago/Reconcile] status "refunded" reportado pero transaction_amount_refunded no confirma un reembolso total — fail closed.', [
                    'payment_webhook_id' => $webhook?->id,
                    'payment_order_id' => $order->id,
                    'reported_transaction_amount' => $truth['transaction_amount'],
                    'reported_transaction_amount_refunded' => $truth['transaction_amount_refunded'],
                    'expected_amount_minor' => $order->amount_minor,
                ]);

                return 'review';
            }

            return $this->applyNormalizedStatus($normalized, $order, $recharge, $webhook, $truth);
        });
    }

    /**
     * Persiste el motivo Y limpia cualquier review_reason previo cuando el
     * resultado NO es 'review' — la columna refleja siempre la anomalía
     * ACTUAL, nunca un historial acumulado. provider_status/status_detail/
     * last_verified_at se actualizan en TODOS los casos (incluidos los que
     * no son 'review'), para que un operador siempre pueda ver cuándo/qué
     * reportó Mercado Pago por última vez sobre este intento, sin volver a
     * llamar a la API.
     *
     * $order es nullable: el llamador puede no tener todavía ninguna
     * PaymentOrder identificada (ej. ProcessMercadoPagoWebhook cuando el
     * propio payload no trae data.id utilizable) — en ese caso solo queda
     * constancia en payment_webhooks (si $webhook tampoco es null) y en el
     * log; en cuanto exista una PaymentOrder identificable, SIEMPRE se le
     * persiste el motivo ahí (review durability).
     *
     * REVIEW AUDIT (ronda de hardening distribuido): `review_detected_at`
     * se fija SOLO al transicionar de "no en revisión" a "en revisión" —
     * detecciones repetidas durante el MISMO episodio activo no reinician
     * el reloj. `review_resolved_at` siempre se limpia a null aquí (una
     * anomalía activa nunca tiene fecha de resolución) — ver
     * recordResolvedTruth() para la transición inversa. Esto le da a un
     * operador tres estados distinguibles sin grepear nada: nunca hubo
     * anomalía (ambos null), anomalía activa (detected_at con valor,
     * resolved_at null), anomalía ya resuelta (ambos con valor).
     *
     * @param  ?PaymentWebhook  $webhook  null cuando el llamador reconcilia una order huérfana (sin webhook local)
     * @param  array{status?:?string,status_detail?:?string}  $truth
     */
    public function markReview(?PaymentOrder $order, ?PaymentWebhook $webhook, string $reason, array $truth = []): void
    {
        if ($order !== null) {
            $wasAlreadyInReview = $order->review_reason !== null;

            $order->update([
                'provider_status' => $truth['status'] ?? null,
                'provider_status_detail' => $truth['status_detail'] ?? null,
                'review_reason' => substr($reason, 0, 500),
                'review_detected_at' => $wasAlreadyInReview ? $order->review_detected_at : now(),
                'review_resolved_at' => null,
                'last_verified_at' => now(),
            ]);
        }

        $webhook?->update([
            'status' => 'review',
            'error' => substr($reason, 0, 500),
        ]);

        Log::warning('[MercadoPago/Reconcile] Marcado para revisión humana — ninguna acción financiera automática.', [
            'payment_webhook_id' => $webhook?->id,
            'payment_order_id' => $order?->id,
            'motivo' => $reason,
        ]);
    }

    /**
     * Contraparte de markReview(): fija `review_resolved_at` SOLO cuando el
     * intento realmente ESTABA en revisión (transición inversa) —
     * resoluciones normales (un intento que nunca tuvo ninguna anomalía)
     * nunca tocan ninguno de los dos timestamps de auditoría.
     *
     * @param  array{status:?string,status_detail:?string}  $truth
     */
    private function recordResolvedTruth(PaymentOrder $order, array $truth): void
    {
        $wasInReview = $order->review_reason !== null;

        $order->update([
            'provider_status' => $truth['status'] ?? null,
            'provider_status_detail' => $truth['status_detail'] ?? null,
            'review_reason' => null,
            'review_resolved_at' => $wasInReview ? now() : $order->review_resolved_at,
            'last_verified_at' => now(),
        ]);
    }

    /**
     * external_reference ahora incluye el número de intento
     * ("recharge:{id}:attempt:{n}") — cada PaymentOrder representa un
     * intento distinto, así que su referencia esperada también lo es (ver
     * MercadoPagoPaymentProvider::createPaymentAttempt()).
     *
     * currency_id FAIL CLOSED: debe existir y ser exactamente igual a
     * PaymentOrder.currency ('PEN'). El monto BRUTO esperado es siempre
     * transaction_amount — nunca transaction_amount_refunded (sección 9).
     *
     * @param  array{transaction_amount:mixed,external_reference:?string,currency_id:?string,collector_id:?string}  $truth
     */
    private function truthMatchesExpectedContext(array $truth, PaymentOrder $order, RechargeRequest $recharge): bool
    {
        if ($truth['external_reference'] !== $order->externalReference()) {
            return false;
        }

        if (! is_numeric($truth['transaction_amount'])) {
            return false;
        }

        // Normaliza el número devuelto (puede venir como float con ruido de
        // punto flotante) a un string de 2 decimales ANTES de convertir a
        // centavos enteros — la comparación en sí siempre es entera, nunca
        // float (sección 8/9 del encargo).
        $reportedAmountMinor = Money::solesToMinor(number_format((float) $truth['transaction_amount'], 2, '.', ''));
        if ($reportedAmountMinor !== $order->amount_minor) {
            return false;
        }

        if ($truth['currency_id'] !== $order->currency) {
            return false;
        }

        $expectedCollectorId = config('payments.mercadopago.expected_collector_id');
        if ($expectedCollectorId && ! empty($truth['collector_id'])
            && (string) $truth['collector_id'] !== (string) $expectedCollectorId) {
            return false;
        }

        return true;
    }

    /**
     * REFUND TRUTH: nunca revierte solo por status/status_detail. Exige
     * transaction_amount_refunded numérico, normalizado a centavos enteros,
     * EXACTAMENTE igual al monto original de la order — un reembolso
     * parcial (0 < refunded < original) o un valor ausente/inconsistente
     * NUNCA confirma un reversal total.
     *
     * @param  array{transaction_amount:mixed,transaction_amount_refunded:mixed}  $truth
     */
    private function refundAmountConfirmsFullReversal(array $truth, PaymentOrder $order): bool
    {
        if (! is_numeric($truth['transaction_amount_refunded'] ?? null)) {
            return false;
        }

        if (! is_numeric($truth['transaction_amount'] ?? null)) {
            return false;
        }

        $refundedMinor = Money::solesToMinor(number_format((float) $truth['transaction_amount_refunded'], 2, '.', ''));
        $totalMinor = Money::solesToMinor(number_format((float) $truth['transaction_amount'], 2, '.', ''));

        return $refundedMinor === $totalMinor && $refundedMinor === $order->amount_minor;
    }

    private function refundMismatchReason(array $truth, PaymentOrder $order): string
    {
        $refunded = $truth['transaction_amount_refunded'] ?? null;

        if (! is_numeric($refunded)) {
            return 'status "refunded" reportado pero transaction_amount_refunded está ausente o no es numérico — no se revierte sin esa confirmación.';
        }

        $refundedMinor = Money::solesToMinor(number_format((float) $refunded, 2, '.', ''));
        if ($refundedMinor <= 0) {
            return 'status "refunded" reportado pero transaction_amount_refunded es cero — inconsistencia, requiere revisión manual.';
        }

        if ($refundedMinor < $order->amount_minor) {
            return "reembolso PARCIAL detectado (transaction_amount_refunded={$refunded}, esperado monto total) — nunca full reversal automático sobre un reembolso parcial.";
        }

        return 'transaction_amount_refunded no coincide exactamente con el monto original del intento — no se revierte sin esa confirmación exacta.';
    }

    /**
     * @param  array{status_detail:?string}  $truth
     */
    private function applyNormalizedStatus(
        string $normalized,
        PaymentOrder $order,
        RechargeRequest $recharge,
        ?PaymentWebhook $webhook,
        array $truth
    ): string {
        return match ($normalized) {
            'paid' => $this->applyPaid($order, $recharge, $truth),
            'pending' => $this->applyResolved($order, $truth, 'pending'),
            'failed' => $this->applyFailed($order, $truth),
            'reversed' => $this->applyReversed($order, $recharge, $webhook, $truth),
            'review' => $this->reviewAndReturn($order, $webhook, "Mercado Pago reportó un estado ambiguo (status_detail={$truth['status_detail']}) que requiere revisión humana — no se automatiza.", $truth),
            default => $this->reviewAndReturn($order, $webhook, "Estado normalizado desconocido: {$normalized}.", $truth),
        };
    }

    private function reviewAndReturn(PaymentOrder $order, ?PaymentWebhook $webhook, string $reason, array $truth): string
    {
        $this->markReview($order, $webhook, $reason, $truth);

        return 'review';
    }

    /**
     * Registra la verdad resuelta (limpia cualquier review_reason previo) y
     * devuelve el outcome tal cual — helper compartido por las ramas que NO
     * necesitan lógica adicional más allá de dejar constancia.
     */
    private function applyResolved(PaymentOrder $order, array $truth, string $outcome): string
    {
        $this->recordResolvedTruth($order, $truth);

        return $outcome;
    }

    private function applyPaid(PaymentOrder $order, RechargeRequest $recharge, array $truth): string
    {
        if ($order->status === 'paid') {
            $this->recordResolvedTruth($order, $truth);

            return 'paid'; // ya procesado por un evento anterior — idempotente
        }

        if (in_array($order->status, ['failed', 'expired', 'cancelled'], true)) {
            Log::warning('[MercadoPago/Reconcile] "paid" recibido para un intento ya en estado terminal — ignorado, no se acredita retroactivamente sin revisión.', [
                'payment_order_id' => $order->id,
                'estado_actual' => $order->status,
            ]);

            $this->recordResolvedTruth($order, $truth);

            return $order->status;
        }

        $order->update(['status' => 'paid', 'paid_at' => now()]);
        // recordResolvedTruth() (no un update inline) para que la
        // transición review_reason≠null → null también fije
        // review_resolved_at cuando corresponda (ver REVIEW AUDIT).
        $this->recordResolvedTruth($order, $truth);
        app(RechargeApprovalService::class)->credit($recharge, null);

        return 'paid';
    }

    private function applyFailed(PaymentOrder $order, array $truth): string
    {
        if ($order->status === 'paid') {
            Log::warning('[MercadoPago/Reconcile] "failed" recibido para un intento que MOVA ya tiene como pagado — no se sobrescribe, requiere revisión manual si es real.', [
                'payment_order_id' => $order->id,
            ]);

            $this->recordResolvedTruth($order, $truth);

            return 'paid';
        }

        if (in_array($order->status, ['failed', 'expired', 'cancelled'], true)) {
            $this->recordResolvedTruth($order, $truth);

            return 'failed'; // ya terminal, idempotente
        }

        $order->update(['status' => 'failed']);
        $this->recordResolvedTruth($order, $truth);

        return 'failed';
    }

    private function applyReversed(PaymentOrder $order, RechargeRequest $recharge, ?PaymentWebhook $webhook, array $truth): string
    {
        if ($recharge->status !== 'approved') {
            return $this->reviewAndReturn($order, $webhook, "Reembolso total confirmado pero RechargeRequest#{$recharge->id} no está 'approved' (está '{$recharge->status}') — inconsistencia, requiere revisión manual.", $truth);
        }

        app(RechargeApprovalService::class)->reverse(
            $recharge,
            null,
            "Mercado Pago: reembolso total confirmado server-to-server (payment {$order->provider_order_id})."
        );

        $this->recordResolvedTruth($order, $truth);

        return 'reversed';
    }

    /**
     * UNKNOWN PAYMENT RECOVERY: resuelve UN PaymentOrder con
     * `submission_status` en `submitting`/`uncertain` (POST /v1/payments
     * sin respuesta definitiva, o el proceso murió justo antes/durante el
     * envío — ver SUBMISSION LIFECYCLE en
     * MercadoPagoPaymentProvider::resolveAttemptRow()) vía
     * `GET /v1/payments/search?external_reference=` —
     * recuperación DIRIGIDA a este intento exclusivamente, nunca un
     * crawler global. Llamada tanto por el gate síncrono dentro de
     * MercadoPagoPaymentProvider::createPaymentAttempt() (sección "TOKEN +
     * IDEMPOTENCY INVARIANT") como por el barrido en lote de
     * MercadoPagoWebhookRecoveryService — MISMO método, sin un segundo
     * subsistema.
     *
     * La búsqueda HTTP corre FUERA de cualquier lock (mismo principio que
     * resolveAttemptRow()/reconcile(): red nunca dentro de una transacción).
     * Al encontrar exactamente un resultado válido, reutiliza reconcile()
     * — el mismo camino normal que procesa un webhook o el recovery de
     * intentos atascados — así que el resultado final (credit/reverse/
     * review) pasa siempre por el mismo choke point financiero.
     *
     * @return string 'resolved'|'still_uncertain'|'exhausted'|'ambiguous'|'search_failed'
     */
    public function reconcileUncertainSubmission(PaymentOrder $order, MercadoPagoPaymentProvider $provider, ?int $maxAttempts = null): string
    {
        $maxAttempts ??= (int) config('payments.mercadopago.recovery.uncertain_search_max_attempts', 5);

        if (! in_array($order->submission_status, ['submitting', 'uncertain'], true) || $order->provider_order_id !== null) {
            // Ya resuelto (por esta misma llamada en otro hilo, o por el
            // camino normal mientras tanto) — idempotente, no-op. También
            // cubre 'prepared' (nada se envió — nada que buscar) por
            // seguridad, aunque el llamador no debería invocar esto sobre
            // una fila 'prepared'.
            return 'resolved';
        }

        $results = $provider->searchPaymentsByExternalReference($order->externalReference());

        if ($results === null) {
            Log::warning('[MercadoPago/Recovery] Búsqueda por external_reference falló (transporte/API) — sigue incierto, no se gasta presupuesto.', [
                'payment_order_id' => $order->id,
            ]);

            return 'search_failed';
        }

        $outcome = DB::transaction(function () use ($order, $results, $maxAttempts) {
            $locked = PaymentOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->submission_status, ['submitting', 'uncertain'], true) || $locked->provider_order_id !== null) {
                return 'resolved';
            }

            if (count($results) === 0) {
                // "No asumir instantáneamente que nunca existió" — se
                // mantiene incierto según budget/backoff, nunca 0
                // resultados = failed inmediato.
                $locked->update(['recovery_attempts' => $locked->recovery_attempts + 1]);

                if ($locked->recovery_attempts >= $maxAttempts) {
                    $locked->update(['submission_status' => null]);
                    $this->markReview(
                        $locked,
                        null,
                        "Búsqueda por external_reference agotó el presupuesto de recuperación ({$locked->recovery_attempts} intentos) sin encontrar el pago — no se puede confirmar ni descartar que Mercado Pago haya creado el cobro."
                    );

                    return 'exhausted';
                }

                return 'still_uncertain';
            }

            if (count($results) > 1) {
                // ANOMALÍA FINANCIERA: nunca se elige uno arbitrariamente,
                // nunca se acredita.
                $locked->update(['submission_status' => null]);
                $this->markReview(
                    $locked,
                    null,
                    'La búsqueda por external_reference devolvió más de un resultado — anomalía financiera, no se elige uno arbitrariamente, no se acredita.',
                    $results[0]
                );

                Log::critical('[MercadoPago/Recovery] external_reference con múltiples pagos — revisión manual obligatoria.', [
                    'payment_order_id' => $locked->id,
                    'external_reference' => $locked->externalReference(),
                    'count' => count($results),
                ]);

                return 'ambiguous';
            }

            $truth = $results[0];
            if (! $this->searchResultMatchesOrder($truth, $locked)) {
                $locked->update(['submission_status' => null]);
                $this->markReview(
                    $locked,
                    null,
                    'La búsqueda por external_reference encontró un resultado que no coincide en monto/moneda/referencia/cuenta — no se acredita.',
                    $truth
                );

                return 'ambiguous';
            }

            $locked->update([
                'provider_order_id' => (string) $truth['id'],
                // 'submitted', no null: mismo significado que en
                // MercadoPagoPaymentProvider::createPaymentAttempt() — id
                // conocido, sin importar el estado de negocio del pago.
                'submission_status' => 'submitted',
            ]);

            return 'found';
        });

        if ($outcome === 'found') {
            // fetchPayment() OTRA VEZ (fuera de cualquier lock) en vez de
            // reutilizar $truth directamente — mismo camino EXACTO que
            // cualquier otro pago (webhook o recovery de intentos
            // atascados), nunca un segundo camino de crédito con lógica
            // propia.
            $this->reconcile($order->fresh(), null, $provider);

            return 'resolved';
        }

        return $outcome;
    }

    /**
     * Mismo contrato de validación que truthMatchesExpectedContext(), pero
     * exige que external_reference coincida EXACTAMENTE (aquí no hay
     * "fetchPayment devolvió un id distinto al consultado" que lo cubra de
     * otra forma — este es el único chequeo antes de persistir
     * provider_order_id) — nunca confía ciegamente en que el único
     * resultado de la búsqueda es realmente el pago correcto.
     *
     * @param  array{transaction_amount:mixed,external_reference:?string,currency_id:?string,collector_id:?string}  $truth
     */
    private function searchResultMatchesOrder(array $truth, PaymentOrder $order): bool
    {
        if (($truth['external_reference'] ?? null) !== $order->externalReference()) {
            return false;
        }

        if (! is_numeric($truth['transaction_amount'] ?? null)) {
            return false;
        }

        $reportedAmountMinor = Money::solesToMinor(number_format((float) $truth['transaction_amount'], 2, '.', ''));
        if ($reportedAmountMinor !== $order->amount_minor) {
            return false;
        }

        if (($truth['currency_id'] ?? null) !== $order->currency) {
            return false;
        }

        $expectedCollectorId = config('payments.mercadopago.expected_collector_id');
        if ($expectedCollectorId && ! empty($truth['collector_id'])
            && (string) $truth['collector_id'] !== (string) $expectedCollectorId) {
            return false;
        }

        return true;
    }
}
