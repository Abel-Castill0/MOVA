<?php

namespace App\Payment\MercadoPago;

/**
 * Forma mínima que una futura capa de checkout (Vue + Card Payment Brick /
 * SDK JS de Mercado Pago) debe entregarle al backend de MOVA para completar
 * una recarga con tarjeta — verificado contra documentación oficial vigente
 * de Checkout API Orders (no memoria de entrenamiento): el Brick tokeniza la
 * tarjeta en el navegador con la PUBLIC KEY de Mercado Pago (nunca con el
 * Access Token, que jamás sale del backend) y entrega este token; el
 * backend nunca ve ni almacena el número de tarjeta/CVV.
 *
 * $token se usa una sola vez (se envía a Mercado Pago dentro de
 * MercadoPagoPaymentProvider::createOrder() y se descarta — MOVA nunca lo
 * persiste en ninguna tabla, ni siquiera en payment_orders/payment_webhooks).
 */
final class TokenizedPaymentMethod
{
    public function __construct(
        // Bandera/identificador del medio de pago (ej. 'visa', 'master') —
        // ver GET /v1/payment_methods, nunca hardcodeado en código.
        public readonly string $id,
        // 'credit_card' | 'debit_card' (documentado oficialmente; Mercado
        // Pago devuelve un error de validación para cualquier otro valor,
        // así que no se restringe aquí con una enum propia).
        public readonly string $type,
        public readonly string $token,
        public readonly int $installments = 1,
    ) {
    }

    /**
     * @return array{id:string,type:string,token:string,installments:int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'token' => $this->token,
            'installments' => $this->installments,
        ];
    }
}
