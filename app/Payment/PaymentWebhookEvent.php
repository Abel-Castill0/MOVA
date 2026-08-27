<?php

namespace App\Payment;

/**
 * Evento de pago ya normalizado y VERIFICADO — solo debe construirse desde
 * dentro de PaymentProviderContract::verifyWebhook(), nunca directamente a
 * partir de un payload HTTP sin pasar por la verificación de firma del
 * proveedor. PaymentWebhookService solo trabaja con esta forma, para que
 * el resto del sistema nunca tenga que conocer el formato específico de
 * Culqi/otro proveedor.
 */
final class PaymentWebhookEvent
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $eventType,
        public readonly ?string $providerOrderId,
        public readonly string $status, // 'paid' | 'failed'
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly array $rawPayload,
    ) {
    }
}
