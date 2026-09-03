<?php

namespace App\Payment\Contracts;

/**
 * Salida de `mp.yape.create()` (MercadoPago.js) tras capturar teléfono+OTP
 * en el navegador — el token de un solo uso es lo ÚNICO que este DTO
 * carga. Deliberadamente NO tiene `paymentMethodId`/`installments`: Mercado
 * Pago documenta `payment_method_id=yape` e `installments=1` como fijos
 * para Yape ("para todos los casos" / "al tratarse de un pago con tarjeta
 * de débito, la cantidad de cuotas será 1") — MercadoPagoPaymentProvider
 * los fuerza server-side, así que el frontend no tiene ningún campo con el
 * que "convertir" un pago Yape en otro medio de pago.
 *
 * Teléfono/OTP NUNCA llegan aquí ni se persisten en ningún lado — solo
 * existen en el navegador, dentro de la llamada a `mp.yape.create()`, que
 * ya devuelve el token antes de que MOVA vea nada.
 */
final class YapePaymentInstrument implements TokenizedPaymentInstrument
{
    public function __construct(
        public readonly string $token,
    ) {
    }

    public function kind(): PaymentMethodKind
    {
        return PaymentMethodKind::Yape;
    }
}
