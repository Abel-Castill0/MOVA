<?php

namespace App\Payment;

/**
 * Evento de pago ya normalizado y VERIFICADO — solo debe construirse desde
 * dentro de PaymentProviderContract::verifyWebhook(), nunca directamente a
 * partir de un payload HTTP sin pasar por la verificación de firma del
 * proveedor. PaymentWebhookService solo trabaja con esta forma, para que
 * el resto del sistema nunca tenga que conocer el formato específico de
 * Culqi/Mercado Pago/otro proveedor.
 *
 * $status ahora tiene 5 valores (antes solo 'paid'/'failed'):
 *   - paid:     pago confirmado — dispara RechargeApprovalService::credit().
 *   - pending:  transición intermedia real del proveedor (orden creada,
 *               esperando pago, en proceso) — se registra pero no acredita
 *               ni falla nada.
 *   - failed:   rechazado/cancelado/expirado — termina la orden sin acreditar.
 *   - reversed: reembolso TOTAL confirmado sobre un pago ya acreditado —
 *               dispara RechargeApprovalService::reverse().
 *   - review:   señal ambigua (reembolso parcial, contracargo sin resultado
 *               financiero confirmado) — MOVA deliberadamente NO actúa
 *               solo, queda para revisión humana.
 *
 * IMPORTANTE (Mercado Pago Orders API): el payload de una notificación de
 * webhook de Orders API NO trae el monto/estado real del pago — solo un id
 * de recurso (`data.id`) y un hint de qué pasó. Para este proveedor,
 * $status/$amountMinor/$currency que arma verifyWebhook() son un HINT
 * informativo para logging/auditoría, nunca la fuente de verdad para
 * decidir si se acredita — eso exige una consulta server-to-server
 * separada (ver MercadoPagoPaymentProvider::fetchOrder() y
 * ProcessMercadoPagoWebhook). Por eso amountMinor/currency son nullable:
 * un proveedor puede no tener esa información en el momento de verificar
 * la firma.
 */
final class PaymentWebhookEvent
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $eventType,
        public readonly ?string $providerOrderId,
        public readonly string $status, // 'paid' | 'pending' | 'failed' | 'reversed' | 'review'
        public readonly ?int $amountMinor,
        public readonly ?string $currency,
        public readonly array $rawPayload,
        // Valores crudos del proveedor (ej. Mercado Pago: status/status_detail
        // reales, cuando el payload los trae) — se preservan sin normalizar,
        // solo para auditoría/depuración. Nunca se usan para decidir nada.
        public readonly ?string $providerStatus = null,
        public readonly ?string $providerStatusDetail = null,
    ) {
    }
}
