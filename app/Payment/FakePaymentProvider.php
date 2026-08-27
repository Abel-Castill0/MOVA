<?php

namespace App\Payment;

use App\Models\PaymentOrder;
use App\Models\RechargeRequest;
use App\Payment\Contracts\PaymentProviderContract;
use Illuminate\Support\Str;

/**
 * Provider de test/desarrollo — nunca se usa en producción (PAYMENT_PROVIDER
 * solo debe valer 'culqi' allí; ver config/payments.php). No habla con
 * ningún servicio externo real: createOrder() genera un provider_order_id
 * local y deja la orden en 'pending', y simulatePaidEvent()/
 * simulateFailedEvent() son los únicos puntos que un test o un comando de
 * consola usan para fabricar el evento que normalmente vendría de un
 * webhook real ya verificado. Por eso verifyWebhook() aquí es trivial (JSON
 * plano, sin firma) — jamás debe exponerse detrás de una ruta pública.
 */
class FakePaymentProvider implements PaymentProviderContract
{
    public function createOrder(RechargeRequest $recharge): PaymentOrder
    {
        return PaymentOrder::create([
            'recharge_request_id' => $recharge->id,
            'provider' => 'fake',
            'provider_order_id' => 'fake_'.Str::uuid(),
            'status' => 'pending',
            'amount_minor' => Money::solesToMinor((string) $recharge->amount_pen),
            'currency' => 'PEN',
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    public function verifyWebhook(string $rawPayload, array $headers): ?PaymentWebhookEvent
    {
        $data = json_decode($rawPayload, true);
        if (! is_array($data) || empty($data['event_id']) || empty($data['provider_order_id'])) {
            return null;
        }

        return new PaymentWebhookEvent(
            eventId: (string) $data['event_id'],
            eventType: (string) ($data['event_type'] ?? 'payment.updated'),
            providerOrderId: (string) $data['provider_order_id'],
            status: (string) ($data['status'] ?? 'paid'),
            amountMinor: (int) ($data['amount_minor'] ?? 0),
            currency: (string) ($data['currency'] ?? 'PEN'),
            rawPayload: $data,
        );
    }

    /**
     * Helper de test: fabrica el evento "pago confirmado" para una orden ya
     * creada por este mismo provider, como si viniera de un webhook real ya
     * verificado. Llamar dos veces con el mismo $eventId debe ser
     * idempotente en PaymentWebhookService — es justo lo que prueban los
     * tests de duplicado.
     */
    public function simulatePaidEvent(PaymentOrder $order, ?string $eventId = null): PaymentWebhookEvent
    {
        return new PaymentWebhookEvent(
            eventId: $eventId ?? 'evt_'.Str::uuid(),
            eventType: 'payment.paid',
            providerOrderId: $order->provider_order_id,
            status: 'paid',
            amountMinor: $order->amount_minor,
            currency: $order->currency,
            rawPayload: ['provider_order_id' => $order->provider_order_id, 'status' => 'paid'],
        );
    }

    public function simulateFailedEvent(PaymentOrder $order, ?string $eventId = null): PaymentWebhookEvent
    {
        return new PaymentWebhookEvent(
            eventId: $eventId ?? 'evt_'.Str::uuid(),
            eventType: 'payment.failed',
            providerOrderId: $order->provider_order_id,
            status: 'failed',
            amountMinor: $order->amount_minor,
            currency: $order->currency,
            rawPayload: ['provider_order_id' => $order->provider_order_id, 'status' => 'failed'],
        );
    }
}
