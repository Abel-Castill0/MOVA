<?php

namespace App\Payment\MercadoPago;

/**
 * Traduce el vocabulario de un pago de Mercado Pago Payments API
 * (status/status_detail de `GET /v1/payments/{id}`) al vocabulario de 5
 * estados que el dominio de MOVA entiende (PaymentWebhookEvent::$status).
 * Reemplaza a MercadoPagoOrderStatusMapper (eliminado en el pivot a
 * Payments API — Orders API ya no se usa en este proyecto).
 *
 * Regla explícita (no negociable, igual que su predecesor): ante cualquier
 * combinación no reconocida o ambigua, el resultado es 'review' — nunca
 * 'paid' ni 'reversed' por default.
 */
final class MercadoPagoPaymentStatusMapper
{
    public static function normalize(string $status, ?string $statusDetail): string
    {
        $statusDetail ??= '';

        return match (true) {
            $status === 'approved' && $statusDetail === 'accredited' => 'paid',

            // Reembolso PARCIAL: el pago sigue "approved" pero con un
            // status_detail que indica que solo una parte volvió — nunca
            // full reversal automático.
            $status === 'approved' && $statusDetail === 'partially_refunded' => 'review',

            $status === 'authorized' && $statusDetail === 'pending_capture' => 'pending',
            $status === 'in_process' => 'pending',
            $status === 'pending' => 'pending',

            $status === 'rejected' => 'failed',
            $status === 'cancelled' => 'failed',

            // Reembolso TOTAL confirmado — las únicas dos variantes de
            // 'refunded' que se automatizan como reversal (cualquier otro
            // status_detail bajo 'refunded' cae al default → review).
            $status === 'refunded' && $statusDetail === 'refunded' => 'reversed',
            $status === 'refunded' && $statusDetail === 'by_admin' => 'reversed',

            $status === 'in_mediation' && $statusDetail === 'pending' => 'review',

            // Contracargo: candidato a reversal, pero NO se automatiza
            // todavía (production blocker explícito — el tópico
            // topic_chargebacks_wh end-to-end sigue sin integrarse). Mismo
            // resultado para las tres variantes documentadas.
            $status === 'charged_back' => 'review',

            default => 'review',
        };
    }
}
