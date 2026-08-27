<?php

namespace App\Payment;

use App\Models\PaymentOrder;
use App\Models\RechargeRequest;
use App\Payment\Contracts\PaymentProviderContract;
use RuntimeException;

/**
 * STUB deliberado — MOVA todavía no tiene cuenta comercial de Culqi (ver
 * docs/payments-architecture.md). No implementar createOrder()/
 * verifyWebhook() hasta:
 *   1. Tener credenciales (CULQI_PUBLIC_KEY/CULQI_PRIVATE_KEY/
 *      CULQI_WEBHOOK_SECRET) de una cuenta real, aunque sea en modo test.
 *   2. Confirmar en el dashboard de Culqi qué métodos están habilitados
 *      para el comercio (Yape/Plin/tarjeta no son automáticos por cuenta).
 *   3. Revisar la documentación vigente de Checkout/Órdenes/Webhooks de
 *      Culqi — no asumir compatibilidad por ejemplos antiguos.
 * El resto del sistema (PaymentOrder, PaymentWebhook, RechargeApprovalService,
 * PaymentWebhookService) ya está listo para recibir esta implementación sin
 * cambios en el núcleo financiero.
 */
class CulqiPaymentProvider implements PaymentProviderContract
{
    public function createOrder(RechargeRequest $recharge): PaymentOrder
    {
        throw new RuntimeException(
            'CulqiPaymentProvider no está implementado todavía: MOVA no tiene cuenta '
            .'comercial de Culqi. Ver app/Payment/CulqiPaymentProvider.php y '
            .'docs/payments-architecture.md antes de habilitar PAYMENT_PROVIDER=culqi.'
        );
    }

    public function verifyWebhook(string $rawPayload, array $headers): ?PaymentWebhookEvent
    {
        throw new RuntimeException(
            'CulqiPaymentProvider no está implementado todavía: MOVA no tiene cuenta '
            .'comercial de Culqi. Ver app/Payment/CulqiPaymentProvider.php y '
            .'docs/payments-architecture.md antes de habilitar PAYMENT_PROVIDER=culqi.'
        );
    }
}
