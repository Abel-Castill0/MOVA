<?php

namespace App\Exceptions;

use RuntimeException;

/** El cierre administrativo de un intento en revisión no se cumple: el mensaje es seguro para mostrar al admin. */
class PaymentReviewCannotBeClosed extends RuntimeException
{
}
