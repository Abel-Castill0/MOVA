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
 *
 * LOST-CREATE-RESPONSE RECOVERY (ronda de recovery-gap): cuando ese mismo
 * intento incierto resulta ser un Challenge 3DS (Mercado Pago SÍ creó el
 * pago con `status_detail=pending_challenge`, pero MOVA nunca llegó a
 * persistir `three_ds_info` porque la respuesta síncrona se perdió), el GET
 * canónico que reconcile() ya hace SIEMPRE antes de decidir nada puede
 * traer un Challenge todavía utilizable — ver
 * recoverChallengeFieldsIfMissing(), llamada desde applyResolved() para el
 * desenlace 'pending'. Mismos invariantes que createPaymentAttempt(): nunca
 * reemplaza un Challenge local ya válido, nunca desliza
 * `three_ds_expires_at` una vez fijado, cero crédito.
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
     * necesitan lógica adicional más allá de dejar constancia. Para el
     * desenlace 'pending', primero intenta recuperar campos de Challenge que
     * el intento local nunca haya capturado (ver
     * recoverChallengeFieldsIfMissing()) — nunca para 'paid'/'failed'/
     * 'reversed', que ya limpian estos campos por su cuenta (applyPaid()/
     * applyFailed()).
     */
    private function applyResolved(PaymentOrder $order, array $truth, string $outcome): string
    {
        if ($outcome === 'pending') {
            $this->recoverChallengeFieldsIfMissing($order, $truth);
        }

        $this->recordResolvedTruth($order, $truth);

        return $outcome;
    }

    /**
     * LOST-CREATE-RESPONSE RECOVERY (ronda de recovery-gap): si este intento
     * local nunca capturó `three_ds_challenge_url`/`three_ds_creq` —
     * típicamente porque la respuesta síncrona de creación se perdió
     * (excepción de red, timeout) y este PaymentOrder llegó hasta aquí vía
     * reconcileUncertainSubmission()/recuperación por external_reference,
     * dejando el intento 'pending'/'pending_challenge' pero sin ningún dato
     * para dibujar el iframe — y esta reconciliación trae un Challenge
     * utilizable en `$truth` (ya extraído y validado HTTPS por el único
     * boundary de extracción, ver
     * MercadoPagoPaymentProvider::extractChallengeFields()/mapPaymentJson()),
     * se persiste AQUÍ. Este es el ÚNICO lugar además de
     * MercadoPagoPaymentProvider::createPaymentAttempt() que escribe estos
     * tres campos a un valor no-null — nunca un segundo camino con su propia
     * validación.
     *
     * NUNCA REEMPLAZA UN CHALLENGE YA VÁLIDO: gateado por
     * `three_ds_challenge_url === null` (comprobado también sobre `creq` por
     * si alguna vez quedaran inconsistentes entre sí) — un Challenge que el
     * profesor ya está completando nunca se reinicia solo porque llegó otra
     * reconciliación de por medio.
     *
     * NUNCA SE DESLIZA: `three_ds_expires_at` solo se fija cuando este
     * bloque decide persistir un Challenge recién recuperado — es decir,
     * exactamente la primera vez que MOVA conoce este Challenge. Cualquier
     * reconciliación posterior sobre el MISMO intento ya recuperado entra
     * al gate de arriba con `three_ds_challenge_url` no-null y no vuelve a
     * tocar nada — mismo invariante que createPaymentAttempt(), ver
     * test_recovered_challenge_expiry_never_slides_across_repeated_reconciliation().
     * Cero riesgo financiero: esta función nunca acredita ni cambia
     * `status` — solo corre dentro del desenlace 'pending' ya decidido por
     * el llamador.
     *
     * @param  array{three_ds_challenge_url?:?string,three_ds_creq?:?string}  $truth
     */
    private function recoverChallengeFieldsIfMissing(PaymentOrder $order, array $truth): void
    {
        if ($order->three_ds_challenge_url !== null || $order->three_ds_creq !== null) {
            return;
        }

        $url = $truth['three_ds_challenge_url'] ?? null;
        $creq = $truth['three_ds_creq'] ?? null;

        if ($url === null || $creq === null) {
            return;
        }

        $order->update([
            'three_ds_challenge_url' => $url,
            'three_ds_creq' => $creq,
            'three_ds_expires_at' => now()->addMinutes(MercadoPagoPaymentProvider::CHALLENGE_WINDOW_MINUTES),
            // COMPENSATION CLAIM (ronda de auditoría de seguridad
            // financiera final, tercera pasada): este es el punto EXACTO
            // en el que "compensar este intento" deja de tener sentido —
            // el Challenge que se estaba dando por perdido acaba de
            // volverse utilizable de nuevo, sin importar QUIÉN disparó
            // esta reconciliación (compensateLostChallenge() propio, la
            // pasada 3 del barrido reconciliando cualquier 'pending'
            // atascado, un futuro webhook). Un claim que un worker de
            // compensación anterior hubiera dejado activo/vencido en esta
            // MISMA fila (p. ej. murió justo antes de completar su propia
            // ronda) se libera aquí — nunca queda huérfano bloqueando una
            // reclamación futura sobre un intento que ya no lo necesita.
            // Deliberadamente NO en recordResolvedTruth()/applyResolved()
            // (que corre en CADA desenlace 'pending', incluidos los que NO
            // recuperan nada nuevo) — eso sí recrearía el bug de
            // concurrencia original (ver docblock de
            // compensateLostChallenge(): "claim → GET sigue pending →
            // claim borrado → un segundo worker reclama"). Este bloque
            // completo es un no-op salvo que de verdad se esté
            // persistiendo un Challenge nuevo — evento raro, nunca "cada
            // poll".
            'compensation_claimed_at' => null,
        ]);
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

        // three_ds_challenge_url/creq/expires_at (MOVA Card Payment Brick
        // 3DS): dato puramente de presentación, ya inútil una vez resuelto
        // el intento — ver docblock de la migración que las agregó. Limpiar
        // aquí (no en recordResolvedTruth(), que también corre para el
        // desenlace 'pending' de CADA poll) evita borrar el iframe del
        // Challenge mientras el profesor todavía lo está completando.
        // `three_ds_expires_at` — NUNCA `payment_orders.expires_at` (columna
        // separada, significado separado — ver
        // MercadoPagoPaymentProvider::createPaymentAttempt()).
        //
        // compensation_claimed_at (ronda de auditoría de seguridad
        // financiera final, tercera pasada): mismo criterio — se limpia
        // AQUÍ, en la transición real a un estado TERMINAL, nunca en
        // recordResolvedTruth() (que corre también para 'pending'). Cubre
        // el caso en que un worker de compensación reclamó este intento y
        // murió antes de completar su propia ronda, y ALGO AJENO a
        // compensateLostChallenge() (esta misma reconciliación, disparada
        // por un webhook, la pasada 3 del barrido, o cualquier otro
        // camino) resuelve el pago de forma independiente — nunca debe
        // quedar un claim huérfano sobre un PaymentOrder ya 'paid'.
        $order->update(['status' => 'paid', 'paid_at' => now(), 'three_ds_challenge_url' => null, 'three_ds_creq' => null, 'three_ds_expires_at' => null, 'compensation_claimed_at' => null]);
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

        // three_ds_challenge_url/creq/three_ds_expires_at/
        // compensation_claimed_at: ver comentario equivalente en
        // applyPaid() — mismo criterio, misma transición real a un estado
        // TERMINAL. Cubre también el desenlace EXITOSO de la propia
        // compensación (Mercado Pago reporta status='cancelled', que el
        // mapper ya traduce a 'failed' — ver docblock de
        // compensateLostChallenge()): sin este reset explícito aquí, el
        // claim que el propio worker de compensación tomó quedaría
        // huérfano tras SU PROPIO éxito.
        $order->update(['status' => 'failed', 'three_ds_challenge_url' => null, 'three_ds_creq' => null, 'three_ds_expires_at' => null, 'compensation_claimed_at' => null]);
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

    /**
     * Ventana de un CLAIM de compensación (ver claimForCompensation()) antes
     * de tratarse como abandonado — tiempo de sobra para una ronda completa
     * (GET + PUT + GET, cada uno con connectTimeout(5)/timeout(15) y hasta 2
     * reintentos en fetchPayment()) sin arriesgar robarle el claim a un
     * worker que sigue genuinamente activo. Deliberadamente UNA constante
     * propia — nunca CHALLENGE_WINDOW_MINUTES (significa algo totalmente
     * distinto: cuánto dura utilizable el Challenge del BANCO frente al
     * profesor) ni ningún umbral de config('mercadopago.recovery') (esos
     * gobiernan presupuestos de BARRIDO, no la duración de un claim
     * individual).
     */
    private const COMPENSATION_CLAIM_STALE_MINUTES = 5;

    /**
     * COMPENSATING CANCELLATION (LOST 3DS CHALLENGE): único punto que
     * decide y ejecuta la cancelación compensatoria de un intento
     * 'pending_challenge' cuyos datos de Challenge MOVA perdió de forma
     * permanente — nunca desde un controlador ni desde
     * MercadoPagoWebhookRecoveryService directamente (ambos solo llaman
     * aquí, ver reconcileStuckOrders()-equivalente en ese servicio).
     * Reutiliza reconcile() para TODA lectura/aplicación de verdad
     * server-to-server: este método nunca interpreta status/status_detail
     * por su cuenta, nunca acredita, nunca marca 'failed' directamente —
     * eso sigue siendo responsabilidad exclusiva de reconcile()/
     * MercadoPagoPaymentStatusMapper, el mismo choke point que usa
     * cualquier otro camino (webhook, recovery sweep, polling).
     *
     * ALGORITMO RACE-SAFE (sección 3 del encargo):
     *   1. Elegibilidad barata, SOLO LECTURA, sin lock — prefiltro rápido
     *      (ver isLostChallengeCompensationCandidate()) para no pagar el
     *      coste de un GET completo sobre una fila obviamente no elegible.
     *      Nunca autoritativa por sí sola — se re-evalúa bajo lock más
     *      abajo.
     *   2. GET canónico PREVIO — vía reconcile(), que hace su propio
     *      fetchPayment(): si el proveedor YA NO está pending, reconcile()
     *      ya aplicó la verdad completa (paid/failed/reversed/review) y
     *      este método NUNCA continúa hacia la cancelación.
     *   3. Re-chequeo ESTRUCTURAL tras ese GET — el propio reconcile() pudo
     *      haber recuperado un Challenge utilizable vía
     *      recoverChallengeFieldsIfMissing(), o el status_detail pudo
     *      cambiar a otra variante de "pending" — nunca se cancela algo
     *      que ya no calza exactamente en el escenario "Challenge perdido
     *      sin datos utilizables".
     *   4. CLAIM ATÓMICO (ver claimForCompensation()) — el ÚNICO punto que
     *      decide si ESTE worker es quien puede llamar a cancelPayment().
     *      Bajo lock, re-verifica TODO de nuevo (elegibilidad estructural +
     *      "sin claim activo de otro worker") y escribe
     *      `compensation_claimed_at` en la MISMA transacción — nunca
     *      separado en dos pasos, nunca sostiene el lock durante I/O de
     *      red. Si el claim falla (otro worker ya lo tiene, dentro de su
     *      ventana normal), este método se DETIENE aquí — cancelPayment()
     *      JAMÁS se invoca sin haber ganado el claim.
     *   5. Solicita la cancelación (MercadoPagoPaymentProvider::
     *      cancelPayment()). Su resultado NUNCA es verdad financiera
     *      suficiente por sí solo (sección 2 del encargo) — se usa solo
     *      para logging/observabilidad.
     *   6. GET canónico FINAL — vía reconcile() otra vez, SIEMPRE, sin
     *      importar qué haya devuelto el PUT. Única fuente de verdad sobre
     *      el desenlace:
     *        - 'paid': crédito exactamente-once (RechargeApprovalService::
     *          credit()) — nunca se fuerza 'failed' solo porque se envió
     *          un PUT de cancelación; si el pago se aprobó en la carrera
     *          entre el GET previo y este, se acredita igual que cualquier
     *          otro pago aprobado.
     *        - 'failed': incluye el caso EXITOSO de esta cancelación —
     *          Mercado Pago reporta `status='cancelled'`, que
     *          MercadoPagoPaymentStatusMapper ya mapea a 'failed' (mismo
     *          criterio que un 'rejected' — decisión deliberada: no se
     *          introduce un tercer mapeo especial para no divergir del
     *          mapper ya probado por el resto del sistema). Mismo estado
     *          terminal: cero créditos, Challenge limpio (applyFailed() ya
     *          limpia three_ds_*), reintento permitido (resolveAttemptRow()
     *          ya trata 'failed' como terminal-reintentable).
     *          CreditCheckoutController::safeStatus() distingue esta
     *          variante de un 'failed' genérico vía `provider_status ===
     *          'cancelled'` (el valor CRUDO que Mercado Pago reportó,
     *          persistido sin cambios por recordResolvedTruth() — nunca un
     *          valor sintético de MOVA, ver ronda de auditoría de
     *          seguridad financiera final) para mostrar la copia
     *          específica de "cancelado de forma segura" en vez del
     *          mensaje genérico de rechazo.
     *        - 'reversed'/'review': semántica existente sin cambios
     *          (altamente improbable para un pago nunca capturado —
     *          `captured: false` según la documentación de cancelación —
     *          pero manejado igual por seguridad).
     *        - 'pending': la cancelación no se confirmó (el PUT falló de
     *          forma incierta, o Mercado Pago simplemente no la aplicó
     *          todavía) — sigue recuperable, NUNCA se fuerza 'failed'
     *          localmente solo porque se intentó un PUT. El claim se DEJA
     *          intacto en este caso (y en 'transport_uncertain') — expira
     *          solo tras COMPENSATION_CLAIM_STALE_MINUTES, nunca antes:
     *          "no premature retry" (sección 3 del encargo).
     *
     * CONCURRENCIA (sección 4 del encargo, ronda de auditoría final):
     * `compensation_claimed_at` (ver claimForCompensation()) es el ÚNICO
     * mecanismo que garantiza como máximo un worker activo por intento —
     * investigado y descartado explícitamente reutilizar
     * `review_reason`/`provider_status`/`submission_status` para esto (ver
     * docblock de la migración 2026_09_03_000002): CUALQUIER columna que
     * reconcile() toque (todas excepto submission_status) se limpiaría sola
     * en cuanto OTRO worker concurrente ejecutara su PROPIO paso 2 (el GET
     * previo es obligatorio para TODOS), sin que el primer worker se
     * enterara — dos workers podrían terminar llamando a cancelPayment()
     * igual. `submission_status` sobrevive a reconcile(), pero ya tiene su
     * propio contrato angosto (cuatro valores, ver
     * MercadoPagoPaymentProvider::resolveAttemptRow()) que un quinto valor
     * sintético habría corrompido igual que el problema original de
     * `provider_status`.
     *
     * @return string outcome: 'not_eligible'|'race_resolved'|'transport_uncertain'|'paid'|'pending'|'failed'|'reversed'|'review'
     */
    public function compensateLostChallenge(PaymentOrder $order, MercadoPagoPaymentProvider $provider, ?int $stuckOrderMinutes = null): string
    {
        $stuckOrderMinutes ??= (int) config('payments.mercadopago.recovery.stuck_order_minutes', 30);

        if (! $this->isLostChallengeCompensationCandidate($order->fresh(), $stuckOrderMinutes)) {
            return 'not_eligible';
        }

        try {
            $preOutcome = $this->reconcile($order->fresh(), null, $provider);
        } catch (RuntimeException $e) {
            Log::warning('[MercadoPago/Compensation] GET canónico previo a la cancelación falló — sigue pending/recuperable, no se cancela.', [
                'payment_order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return 'transport_uncertain';
        }

        if ($preOutcome !== 'pending') {
            // El GET PREVIO ya resolvió el intento a un desenlace terminal
            // (paid/failed/reversed/review) — ver escenarios A/B del
            // encargo ("pre/final GET → cancelled/approved → claim
            // cleared"). Ningún claim se tomó todavía EN ESTA llamada
            // (claimForCompensation() vive más abajo), pero un worker
            // ANTERIOR pudo haber dejado uno activo/vencido antes de morir
            // — se libera de forma defensiva e incondicional (no-op seguro
            // si ya era null) para no dejar rastro huérfano sobre un
            // intento que la propia compensación ya sabe que terminó.
            PaymentOrder::whereKey($order->id)->update(['compensation_claimed_at' => null]);

            return $preOutcome;
        }

        $fresh = $order->fresh();
        if (! $this->isStructurallyCancellable($fresh)) {
            // El GET previo cambió las condiciones (recuperó un Challenge
            // utilizable, o el status_detail ya no es 'pending_challenge')
            // — nunca se cancela algo que ya no calza en el escenario
            // exacto de esta compensación (escenario C del encargo: "pre-
            // cancel GET recovers a valid actionable Challenge →
            // compensation stops → claim cleared → normal Challenge
            // remains usable"). Mismo criterio defensivo que arriba: se
            // libera cualquier claim que pudiera haber quedado activo de
            // un worker anterior — el Challenge recién recuperado sigue
            // 100% intacto/usable, esto solo limpia el mutex interno de
            // MOVA, nunca toca three_ds_*.
            PaymentOrder::whereKey($fresh->id)->update(['compensation_claimed_at' => null]);

            return 'race_resolved';
        }

        if (! $this->claimForCompensation($fresh)) {
            // Otro worker ya tiene el claim activo (dentro de su ventana
            // normal), o una revisión humana real está en curso sobre este
            // MISMO intento por otro motivo — nunca se pisa ninguna de las
            // dos. cancelPayment() JAMÁS se invoca sin haber ganado el
            // claim.
            return 'not_eligible';
        }

        $cancelResult = $provider->cancelPayment($fresh->provider_order_id);
        Log::info('[MercadoPago/Compensation] Cancelación compensatoria de Challenge perdido solicitada.', [
            'payment_order_id' => $fresh->id,
            'resultado_put' => $cancelResult['outcome'],
        ]);

        try {
            $outcome = $this->reconcile($fresh->fresh(), null, $provider);
        } catch (RuntimeException $e) {
            Log::warning('[MercadoPago/Compensation] GET canónico final tras la cancelación falló — sigue pending/recuperable.', [
                'payment_order_id' => $fresh->id,
                'error' => $e->getMessage(),
            ]);

            // El claim se DEJA intacto — expira solo tras
            // COMPENSATION_CLAIM_STALE_MINUTES (ver docblock de la clase).
            return 'transport_uncertain';
        }

        if ($outcome !== 'pending') {
            // Terminal (paid/failed/reversed/review): la compensación
            // realmente terminó — se libera el claim de inmediato, no hace
            // falta esperar a que expire por sí solo.
            PaymentOrder::whereKey($fresh->id)->update(['compensation_claimed_at' => null]);
        }
        // 'pending' (la cancelación no se confirmó): el claim se DEJA
        // intacto — mismo criterio que transport_uncertain arriba, "no
        // premature retry".

        return $outcome;
    }

    /**
     * ATOMIC CLAIM (ronda de auditoría de seguridad financiera final,
     * sección 3): bajo el MISMO lockForUpdate() que el resto del código
     * financiero ya usa (nunca un mecanismo de locking nuevo, nunca
     * sostenido durante I/O de red — la transacción entera es puramente
     * local), re-verifica que $order sigue estructuralmente cancelable Y
     * que ningún OTRO worker tiene un claim activo, y si ambas cosas se
     * cumplen, escribe `compensation_claimed_at = now()` en la MISMA
     * transacción antes de devolver true. Un claim "activo" es uno cuyo
     * timestamp es más reciente que COMPENSATION_CLAIM_STALE_MINUTES — más
     * viejo que eso se trata como abandonado (el worker que lo tomó murió
     * antes de completar su ronda) y se sobrescribe con un timestamp
     * nuevo, permitiendo que ESTE worker retome la compensación — nunca
     * queda un intento permanentemente atascado.
     *
     * DELIBERADAMENTE en su PROPIA columna (`compensation_claimed_at`,
     * nunca `review_reason`/`provider_status`/`submission_status`) — ver
     * docblock de la migración 2026_09_03_000002 y de
     * compensateLostChallenge() para la evidencia completa de por qué
     * ninguna columna existente puede representar esto con seguridad.
     *
     * "STALE CLAIM ON A TERMINAL ORDER" (excepción documentada, ronda de
     * auditoría de seguridad financiera final, segunda pasada) —
     * compensateLostChallenge() limpia el claim explícitamente en TODOS
     * los desenlaces que ella misma determina (pre-check terminal,
     * race_resolved, post-check terminal — ver esos tres puntos más
     * arriba). El ÚNICO caso que puede dejar `compensation_claimed_at`
     * huérfano en una fila YA terminal es que ALGO AJENO a esta
     * compensación (un webhook, otra pasada del barrido) resuelva el MISMO
     * PaymentOrder de forma independiente mientras un claim de un worker
     * de compensación previo (activo o ya vencido) sigue sin limpiar — un
     * evento genuinamente raro (requiere que ambas cosas coincidan en la
     * misma ventana). Deliberadamente NO se resuelve haciendo que
     * reconcile()/recordResolvedTruth() conozcan esta columna: sería
     * exactamente el mismo error que motivó esta ronda de auditoría (mezclar
     * lógica de claim de esta feature en el choke point compartido y ya
     * probado por webhook/polling/todas las demás pasadas del barrido). Es
     * seguro dejarlo así porque el valor queda PROBADAMENTE inerte:
     * isLostChallengeCompensationCandidate() — la ÚNICA puerta de entrada
     * que consulta esta columna, vía claimForCompensation() — exige
     * `status === 'pending'` antes que cualquier otra cosa; una fila
     * terminal nunca vuelve a pasar por ahí, así que un
     * `compensation_claimed_at` residual ahí nunca vuelve a leerse ni a
     * bloquear ni a permitir nada.
     */
    private function claimForCompensation(PaymentOrder $order): bool
    {
        return DB::transaction(function () use ($order) {
            $locked = PaymentOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $this->isStructurallyCancellable($locked)) {
                return false;
            }

            if ($locked->compensation_claimed_at !== null
                && $locked->compensation_claimed_at->gt(now()->subMinutes(self::COMPENSATION_CLAIM_STALE_MINUTES))) {
                return false;
            }

            $locked->update(['compensation_claimed_at' => now()]);

            return true;
        });
    }

    /**
     * "No locally-valid actionable Challenge data" (sección 1 del
     * encargo): un Challenge cuenta como UTILIZABLE solo con los tres
     * campos presentes Y `three_ds_expires_at` todavía en el futuro —
     * mismo criterio que CreditCheckoutController::safeStatus() ya usa
     * para decidir si expone `action_required` al frontend (nunca un
     * segundo criterio divergente).
     */
    private function hasUsableChallenge(PaymentOrder $order): bool
    {
        return $order->three_ds_challenge_url !== null
            && $order->three_ds_creq !== null
            && $order->three_ds_expires_at !== null
            && $order->three_ds_expires_at->isFuture();
    }

    /**
     * Condiciones ESTRUCTURALES del escenario "Challenge 3DS perdido" —
     * evaluables en cualquier momento, sin referencia a cuánto tiempo lleva
     * atascado el intento (esa parte, el "presupuesto de recuperación", vive
     * aparte en isLostChallengeCompensationCandidate() porque
     * compensateLostChallenge() necesita re-evaluar SOLO esta parte después
     * de su propio GET previo — ese GET ya actualiza `updated_at`, así que
     * reutilizar el gate completo ahí se auto-invalidaría de inmediato).
     *
     * NUNCA cancela un Challenge normal/usable: el chequeo central es
     * `! hasUsableChallenge()`. `submission_status === 'submitted'` excluye
     * por construcción cualquier fila que todavía pertenezca al OTRO camino
     * de recuperación existente (`submitting`/`uncertain`, ver
     * reconcileUncertainSubmission()) — ambos presupuestos nunca se pisan.
     */
    private function isStructurallyCancellable(PaymentOrder $order): bool
    {
        return $order->provider === 'mercadopago'
            && $order->status === 'pending'
            && $order->submission_status === 'submitted'
            && $order->provider_order_id !== null
            && $order->provider_status_detail === 'pending_challenge'
            && ! $this->hasUsableChallenge($order);
    }

    /**
     * ELIGIBILITY CONTRACT completo (sección 1 del encargo) — candidato a
     * cancelación compensatoria si, ADEMÁS de isStructurallyCancellable():
     *
     *   - ya se verificó server-to-server al menos una vez
     *     (`last_verified_at` no-null) — nunca se compensa un intento cuya
     *     única "verdad" conocida es la respuesta síncrona original de
     *     createPaymentAttempt(); tiene que haber pasado por al menos una
     *     reconciliación real que haya tenido oportunidad de recuperar el
     *     Challenge vía recoverChallengeFieldsIfMissing() y confirmado que
     *     sigue sin datos utilizables.
     *   - el intento lleva existiendo (`created_at`) al menos
     *     `$stuckOrderMinutes` — reutiliza DELIBERADAMENTE el MISMO umbral
     *     que ya define "intento pending atascado" para el barrido en lote
     *     existente (MercadoPagoWebhookRecoveryService::
     *     reconcileStuckOrders(),
     *     config('payments.mercadopago.recovery.stuck_order_minutes'),
     *     default 30 minutos) — es el "presupuesto de recuperación normal"
     *     al que se refiere la sección 1 del encargo ("existing
     *     recovery/search budget has reached the point where normal
     *     Challenge reconstruction is no longer possible"): ningún número
     *     mágico nuevo, ninguna columna de contador nueva, ninguna
     *     state machine nueva — PaymentOrder ya expresa esto con las
     *     columnas que tiene.
     *
     *     DELIBERADAMENTE `created_at`, NUNCA `updated_at` (encontrado en
     *     vivo escribiendo el test de cableado del barrido, ver
     *     MercadoPagoWebhookRecoveryServiceTest): `reconcileStuckOrders()`
     *     (pasada 3) visita esta MISMA fila en CADA barrido mientras siga
     *     'pending' y llama a reconcile(), que SIEMPRE actualiza
     *     `updated_at` (y `last_verified_at`) vía recordResolvedTruth() —
     *     sin importar si el Challenge sigue perdido. Si esta elegibilidad
     *     hubiera usado `updated_at`, la pasada 3 (que corre ANTES que
     *     esta, dentro del mismo recover()) refrescaría el reloj en cada
     *     barrido y este umbral nunca se cumpliría jamás — un gate que se
     *     auto-invalida solo, silenciosamente, sin ningún error visible.
     *     `created_at` es inmutable una vez creada la fila — el único
     *     ancla de tiempo que ninguna reconciliación normal puede tocar.
     */
    public function isLostChallengeCompensationCandidate(PaymentOrder $order, ?int $stuckOrderMinutes = null): bool
    {
        $stuckOrderMinutes ??= (int) config('payments.mercadopago.recovery.stuck_order_minutes', 30);

        return $this->isStructurallyCancellable($order)
            && $order->last_verified_at !== null
            && $order->created_at !== null
            && $order->created_at->lte(now()->subMinutes($stuckOrderMinutes));
    }
}
