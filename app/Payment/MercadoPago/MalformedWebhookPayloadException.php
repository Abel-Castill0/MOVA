<?php

namespace App\Payment\MercadoPago;

use RuntimeException;

/**
 * La firma del webhook era válida (Mercado Pago realmente lo envió), pero
 * el cuerpo no tiene la forma mínima esperada (falta 'id' raíz o 'data.id').
 * Distinto de una firma inválida a propósito: el controller responde 401
 * para firma inválida y una respuesta distinta (sin filtrar el motivo
 * exacto al cliente) para esto — ver MercadoPagoWebhookController.
 */
final class MalformedWebhookPayloadException extends RuntimeException
{
}
