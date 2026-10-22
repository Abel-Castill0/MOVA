<?php

namespace App\Payment\Culqi;

use RuntimeException;

/**
 * La petición NO salió de MOVA (p. ej. falló el cifrado RSA antes de enviar).
 * A diferencia de un timeout, aquí se sabe con certeza que Culqi no recibió nada,
 * así que el intento puede volver a 'prepared' en lugar de quedar 'uncertain'.
 */
final class CulqiNotSentException extends RuntimeException
{
}
