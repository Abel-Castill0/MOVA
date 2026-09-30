<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Falló la entrega de una notificación SOLO CORREO (ver SafeMailChannel).
 *
 * Existe para que los flujos síncronos (registro, reenvío de verificación,
 * recuperación de contraseña) puedan recuperarse de ESTE fallo concreto sin
 * capturar `Throwable`: cualquier otra excepción sigue fallando en voz alta.
 * En cola nadie la captura, así que el job falla y usa los reintentos.
 *
 * El mensaje es fijo a propósito: nunca lleva destinatario, respuesta del
 * proveedor, credenciales ni cuerpo del correo. La causa real viaja en
 * `getPrevious()` para diagnóstico (Sentry / logs).
 */
class MailDeliveryException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('La entrega del correo falló.', 0, $previous);
    }
}
