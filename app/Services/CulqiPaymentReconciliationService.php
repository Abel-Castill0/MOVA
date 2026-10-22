<?php

namespace App\Services;

use App\Models\OperationalAlert;
use App\Models\PaymentOrder;
use App\Models\PaymentWebhook;
use App\Models\RechargeRequest;
use App\Payment\Culqi\CulqiChargeMapper;
use App\Payment\Culqi\CulqiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Única puerta por la que Culqi puede cambiar el estado de un PaymentOrder y,
 * a través de RechargeApprovalService, acreditar créditos.
 *
 * Principios (mismos que Mercado Pago):
 *  - La VERDAD sale de la API de Culqi (GET /v2/charges/{id} con la llave
 *    secreta), nunca del cuerpo de un webhook — Culqi no firma sus
 *    notificaciones.
 *  - El cargo debe coincidir con el contexto que MOVA fijó al crearlo
 *    (metadata.payment_order_id / recharge_request_id / idempotency_key,
 *    importe y moneda del PaymentOrder, modo test/live). Cualquier
 *    discrepancia = 'review', sin acreditar.
 *  - Todo bajo transacción con lockForUpdate; el crédito es exactamente-una-vez
 *    por RechargeRequest (RechargeApprovalService::credit + idempotency_key).
 */
class CulqiPaymentReconciliationService
{
    public function __construct(private readonly CulqiClient $client)
    {
    }

    /** Consulta el cargo en Culqi y aplica el resultado. Devuelve el estado local resultante. */
    public function reconcile(PaymentOrder $order, ?PaymentWebhook $webhook = null): string
    {
        $chargeId = $order->provider_order_id;

        if ($chargeId === null) {
            throw new RuntimeException("CulqiPaymentReconciliationService: PaymentOrder#{$order->id} todavía no tiene provider_order_id — nada que consultar.");
        }

        $result = $this->client->getCharge($chargeId);

        if (! $result->successful()) {
            // No se sabe nada nuevo: se reintenta luego (job/barrido), no se cambia el estado.
            throw new RuntimeException("CulqiPaymentReconciliationService: no se pudo consultar el cargo {$chargeId} (HTTP {$result->status}).");
        }

        return $this->applyTruth($order, $webhook, $result->body);
    }

    /**
     * Aplica un objeto `charge` ya obtenido directamente de la API de Culqi
     * (respuesta de POST /v2/charges o GET /v2/charges/{id}) — NUNCA un
     * payload de webhook.
     *
     * @param  array<string, mixed>  $charge
     */
    public function applyTruth(PaymentOrder $order, ?PaymentWebhook $webhook, array $charge): string
    {
        return DB::transaction(function () use ($order, $webhook, $charge) {
            $order = PaymentOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $recharge = RechargeRequest::whereKey($order->recharge_request_id)->lockForUpdate()->firstOrFail();

            $mismatch = $this->contextMismatch($charge, $order, $recharge);
            if ($mismatch !== null) {
                $this->markReview($order, $webhook, $mismatch, $charge);

                return 'review';
            }

            $normalized = CulqiChargeMapper::normalize($charge);

            return match ($normalized) {
                'paid' => $this->applyPaid($order, $recharge, $charge),
                'failed' => $this->applyFailed($order, $charge),
                'reversed' => $this->applyReversed($order, $recharge, $webhook, $charge),
                default => $this->reviewAndReturn($order, $webhook, $this->reviewReason($charge), $charge),
            };
        });
    }

    /**
     * Intento con resultado incierto (timeout/5xx al crear el cargo): busca en
     * Culqi un cargo que lleve la metadata de ESTE intento. Si lo encuentra,
     * aplica su verdad; si no lo encuentra, NO se asume nada hasta agotar el
     * presupuesto de búsquedas (entonces pasa a revisión humana, nunca a
     * "fallido" a ciegas: un cargo real podría aparecer tarde).
     */
    public function reconcileUncertainSubmission(PaymentOrder $order): string
    {
        $order = PaymentOrder::whereKey($order->id)->firstOrFail();

        if ($order->provider_order_id !== null) {
            return $this->reconcile($order);
        }

        // Otra petición (doble clic) o el cron coinciden con un POST todavía en vuelo: aún no es
        // "incierto" y no debe consumir el presupuesto de búsquedas ni acabar en una alerta falsa.
        $inFlightSeconds = (int) config('payments.culqi.recovery.in_flight_grace_seconds', 120);
        if ($order->submission_status === 'submitting' && $order->updated_at !== null && $order->updated_at->gt(now()->subSeconds($inFlightSeconds))) {
            return 'in_flight';
        }

        $recharge = RechargeRequest::with('teacherProfile.user')->findOrFail($order->recharge_request_id);
        $window = (int) config('payments.culqi.recovery.uncertain_search_window_minutes', 60);
        $from = ($order->created_at ?? now())->copy()->subMinutes(2)->timestamp;

        $query = [
            'amount' => $order->amount_minor,
            'currency_code' => $order->currency,
            'creation_date_from' => $from,
            'creation_date_to' => $from + (($window + 2) * 60),
            'limit' => 100,
        ];
        if (filled($email = $recharge->teacherProfile?->user?->email)) {
            $query['email'] = $email;
        }

        $result = $this->client->listCharges($query);

        if (! $result->successful()) {
            return $this->bumpSearchAttempt($order, 'búsqueda no concluyente (HTTP '.$result->status.')');
        }

        foreach (($result->body['data'] ?? []) as $candidate) {
            if (is_array($candidate) && $this->metadataMatches($candidate, $order)) {
                $order->update(['provider_order_id' => (string) $candidate['id'], 'submission_status' => 'submitted']);

                return $this->applyTruth($order->fresh(), null, $candidate);
            }
        }

        return $this->bumpSearchAttempt($order, 'ningún cargo con la metadata de este intento');
    }

    public function markReview(?PaymentOrder $order, ?PaymentWebhook $webhook, string $reason, array $charge = []): void
    {
        if ($order !== null) {
            $wasInReview = $order->review_reason !== null;

            $order->update([
                'provider_status' => $this->providerStatus($charge),
                'provider_status_detail' => $this->providerDetail($charge),
                'review_reason' => substr($reason, 0, 500),
                'review_detected_at' => $wasInReview ? $order->review_detected_at : now(),
                'review_resolved_at' => null,
                'last_verified_at' => now(),
            ]);
        }

        $webhook?->update(['status' => 'review', 'error' => substr($reason, 0, 500)]);

        Log::warning('[Culqi/Reconcile] Marcado para revisión humana — ninguna acción financiera automática.', [
            'payment_webhook_id' => $webhook?->id,
            'payment_order_id' => $order?->id,
            'motivo' => $reason,
        ]);

        app(OperationalAlertService::class)->raise(
            key: $order !== null ? "payment_order:{$order->id}:review" : "payment_webhook:{$webhook?->id}:review",
            type: $order !== null ? OperationalAlert::TYPE_PAYMENT_REVIEW : OperationalAlert::TYPE_WEBHOOK_REVIEW,
            title: 'Pago de Culqi en revisión',
            message: 'Un pago de Culqi quedó marcado para revisión humana y MOVA no ha aplicado ninguna acción '
                .'financiera automática sobre él. Requiere una decisión manual.',
            context: array_filter([
                'Motivo' => $reason,
                'PaymentOrder' => $order?->id,
                'Recarga' => $order?->recharge_request_id,
                'Intento' => $order?->attempt_number,
                'PaymentWebhook' => $webhook?->id,
                'Estado en el proveedor' => $this->providerStatus($charge),
            ], fn ($value) => $value !== null),
            severity: OperationalAlert::SEVERITY_CRITICAL,
        );
    }

    // ── Estados ───────────────────────────────────────────────────────────

    private function applyPaid(PaymentOrder $order, RechargeRequest $recharge, array $charge): string
    {
        if ($order->status === 'paid') {
            $this->recordResolvedTruth($order, $charge);

            return 'paid'; // idempotente: ya procesado por otro evento/barrido
        }

        if (in_array($order->status, ['failed', 'expired', 'cancelled'], true)) {
            Log::warning('[Culqi/Reconcile] "paid" para un intento ya terminal — no se acredita retroactivamente sin revisión.', [
                'payment_order_id' => $order->id,
                'estado_actual' => $order->status,
            ]);

            return $this->reviewAndReturn($order, null, "Culqi reporta pagado un intento que MOVA ya había cerrado como '{$order->status}'.", $charge);
        }

        // Cobro confirmado sobre una recarga que ya no admite acreditación (rechazada/revertida a mano,
        // o de otro método): jamás se lanza una excepción que deje el dinero cobrado sin rastro; se
        // marca para decisión humana (probable devolución).
        if (! in_array($recharge->status, ['pending', 'approved'], true) || $recharge->payment_method !== 'culqi') {
            return $this->reviewAndReturn($order, null, "Cobro confirmado por Culqi sobre la recarga #{$recharge->id} en estado '{$recharge->status}' (método '{$recharge->payment_method}'): no se acredita; requiere decisión humana (posible devolución).", $charge);
        }

        $order->update([
            'status' => 'paid',
            'paid_at' => now(),
            'provider_order_id' => (string) $charge['id'],
            'submission_status' => 'submitted',
        ]);
        $this->recordResolvedTruth($order, $charge);
        app(RechargeApprovalService::class)->credit($recharge, null);

        return 'paid';
    }

    private function applyFailed(PaymentOrder $order, array $charge): string
    {
        if ($order->status === 'paid') {
            return $this->reviewAndReturn($order, null, 'Culqi reporta rechazado un cargo que MOVA ya tiene como pagado.', $charge);
        }

        if (! in_array($order->status, ['failed', 'expired', 'cancelled'], true)) {
            $order->update(['status' => 'failed', 'provider_order_id' => (string) $charge['id'], 'submission_status' => 'submitted']);
        }

        $this->recordResolvedTruth($order, $charge);

        return 'failed';
    }

    private function applyReversed(PaymentOrder $order, RechargeRequest $recharge, ?PaymentWebhook $webhook, array $charge): string
    {
        if ($recharge->status === 'reversed') {
            $this->recordResolvedTruth($order, $charge);

            return 'reversed'; // idempotente: la devolución ya se aplicó antes (reintento de webhook / barrido)
        }

        if ($recharge->status !== 'approved') {
            return $this->reviewAndReturn($order, $webhook, "Devolución total en Culqi pero la recarga #{$recharge->id} no está 'approved' (está '{$recharge->status}') — requiere revisión manual.", $charge);
        }

        if (CulqiChargeMapper::refundedMinor($charge) !== $order->amount_minor) {
            return $this->reviewAndReturn($order, $webhook, 'La devolución de Culqi no coincide con el importe cobrado — requiere revisión manual.', $charge);
        }

        app(RechargeApprovalService::class)->reverse(
            $recharge,
            null,
            "Culqi: devolución total confirmada server-to-server (cargo {$order->provider_order_id})."
        );
        $this->recordResolvedTruth($order, $charge);

        return 'reversed';
    }

    private function reviewAndReturn(PaymentOrder $order, ?PaymentWebhook $webhook, string $reason, array $charge): string
    {
        $this->markReview($order, $webhook, $reason, $charge);

        return 'review';
    }

    // ── Validación de contexto ────────────────────────────────────────────

    /** Devuelve el motivo del desajuste o null si el cargo corresponde exactamente a este intento. */
    private function contextMismatch(array $charge, PaymentOrder $order, RechargeRequest $recharge): ?string
    {
        $id = $charge['id'] ?? null;
        if (! is_string($id) || $id === '') {
            return 'La respuesta de Culqi no trae id de cargo.';
        }

        if ($order->provider_order_id !== null && $order->provider_order_id !== $id) {
            return 'El cargo consultado no es el registrado en el intento (id distinto).';
        }

        $expectedPrefix = \App\Payment\Culqi\CulqiConfig::isTestMode() ? 'chr_test_' : 'chr_live_';
        if (! str_starts_with($id, $expectedPrefix)) {
            return 'El cargo pertenece a otro entorno de Culqi (test/live) que el configurado.';
        }

        if (! $this->metadataMatches($charge, $order)) {
            return 'La metadata del cargo (payment_order_id/recharge_request_id/idempotency_key) no coincide con este intento.';
        }

        if ((int) ($charge['amount'] ?? -1) !== $order->amount_minor || ($charge['currency_code'] ?? null) !== $order->currency) {
            return 'Importe o moneda del cargo no coinciden con lo congelado en el intento — posible pago equivocado, no se acredita.';
        }

        if ($order->recharge_request_id !== $recharge->id) {
            return 'Inconsistencia entre el intento y la recarga.';
        }

        return null;
    }

    private function metadataMatches(array $charge, PaymentOrder $order): bool
    {
        $meta = $charge['metadata'] ?? null;

        return is_array($meta)
            && (string) ($meta['payment_order_id'] ?? '') === (string) $order->id
            && (string) ($meta['recharge_request_id'] ?? '') === (string) $order->recharge_request_id
            && $order->idempotency_key !== null
            && hash_equals((string) $order->idempotency_key, (string) ($meta['idempotency_key'] ?? ''));
    }

    private function reviewReason(array $charge): string
    {
        $refunded = CulqiChargeMapper::refundedMinor($charge);
        if ($refunded > 0) {
            return 'Devolución parcial en Culqi — MOVA no actúa solo.';
        }
        if (($charge['dispute'] ?? false) === true) {
            return 'Cargo en disputa/contracargo en Culqi — MOVA no actúa solo.';
        }

        return 'Estado del cargo en Culqi no reconocido como pagado ni rechazado (outcome.code='
            .(is_array($charge['outcome'] ?? null) ? ($charge['outcome']['code'] ?? 'null') : 'null').') — fail closed.';
    }

    private function bumpSearchAttempt(PaymentOrder $order, string $why): string
    {
        $max = (int) config('payments.culqi.recovery.uncertain_search_max_attempts', 5);

        // Bajo lock: dos barridos/peticiones simultáneas no pierden incrementos del contador.
        return DB::transaction(function () use ($order, $max, $why) {
            $locked = PaymentOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $attempts = $locked->recovery_attempts + 1;
            $locked->update(['recovery_attempts' => $attempts, 'last_verified_at' => now()]);

            if ($attempts >= $max) {
                $this->markReview($locked, null, "Intento con resultado incierto sin cargo localizable tras {$attempts} búsquedas ({$why}). No se cierra automáticamente: un cargo real podría aparecer.");

                return 'review';
            }

            return 'uncertain';
        });
    }

    private function recordResolvedTruth(PaymentOrder $order, array $charge): void
    {
        $wasInReview = $order->review_reason !== null;

        $order->update([
            'provider_status' => $this->providerStatus($charge),
            'provider_status_detail' => $this->providerDetail($charge),
            'review_reason' => null,
            'review_resolved_at' => $wasInReview ? now() : $order->review_resolved_at,
            'last_verified_at' => now(),
        ]);

        if ($wasInReview) {
            app(OperationalAlertService::class)->resolve("payment_order:{$order->id}:review");
        }
    }

    private function providerStatus(array $charge): ?string
    {
        if ($charge === []) {
            return null;
        }

        return ($charge['paid'] ?? false) === true ? 'paid' : 'not_paid';
    }

    private function providerDetail(array $charge): ?string
    {
        $code = is_array($charge['outcome'] ?? null) ? ($charge['outcome']['code'] ?? null) : null;
        $decline = is_array($charge['outcome'] ?? null) ? ($charge['outcome']['decline_code'] ?? null) : null;

        return $code === null ? null : mb_substr($code.($decline ? ':'.$decline : ''), 0, 64);
    }
}
