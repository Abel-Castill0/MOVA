<?php

namespace App\Payment\Contracts;

/**
 * Salida real de Card Payment Brick / MercadoPago.js para una tarjeta —
 * solo los campos que la documentación oficial de Payments API declara
 * necesarios para `POST /v1/payments` (ver docblock de
 * MercadoPagoPaymentProvider). $token es de un solo uso: MOVA nunca lo
 * persiste (ni en DB, ni en logs, ni en el contexto de una excepción, ni en
 * un payload de cola) — se usa una vez para la llamada HTTP y se descarta.
 *
 * $paymentMethodId es la bandera de la tarjeta (ej. 'visa', 'master'), no
 * un tipo de tarjeta libre — la Brick lo entrega ya resuelto.
 * $identification* es opcional a propósito: Mercado Pago lo exige según
 * el medio de pago/monto/país, no siempre.
 */
final class CardPaymentInstrument implements TokenizedPaymentInstrument
{
    public function __construct(
        public readonly string $token,
        public readonly string $paymentMethodId,
        public readonly int $installments = 1,
        public readonly ?string $issuerId = null,
        public readonly ?string $identificationType = null,
        public readonly ?string $identificationNumber = null,
    ) {
    }

    public function kind(): PaymentMethodKind
    {
        return PaymentMethodKind::Card;
    }
}
