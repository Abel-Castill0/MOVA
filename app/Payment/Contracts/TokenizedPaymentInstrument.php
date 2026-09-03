<?php

namespace App\Payment\Contracts;

/**
 * Lo único que una futura capa de checkout puede entregarle al backend:
 * un medio de pago ya tokenizado por el cliente Mercado Pago (Card Payment
 * Brick / `mp.yape`) — nunca datos crudos de tarjeta/OTP, nunca el
 * monto/créditos/moneda/referencia (eso lo decide MOVA server-side siempre,
 * a partir de la RechargeRequest ya existente — ver
 * PaymentProviderContract::createPaymentAttempt()).
 *
 * Interfaz provider-agnostic a propósito (vive en App\Payment\Contracts,
 * no en App\Payment\MercadoPago): un futuro provider distinto de Mercado
 * Pago también recibiría instrumentos de este mismo tipo — el controller
 * de checkout nunca necesita saber qué proveedor está detrás de
 * PaymentProviderContract para construir uno.
 */
interface TokenizedPaymentInstrument
{
    public function kind(): PaymentMethodKind;
}
