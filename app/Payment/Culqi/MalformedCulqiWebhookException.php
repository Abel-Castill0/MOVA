<?php

namespace App\Payment\Culqi;

use RuntimeException;

/** Notificación de Culqi con el secreto correcto pero estructura inutilizable. */
final class MalformedCulqiWebhookException extends RuntimeException
{
}
