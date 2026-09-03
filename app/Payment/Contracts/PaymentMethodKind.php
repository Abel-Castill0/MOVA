<?php

namespace App\Payment\Contracts;

/**
 * Enum (PHP 8.1, sin elevar el runtime — ver composer.json "php": "^8.1")
 * en vez de un `kind(): string` con valores libres: el compilador impide
 * cualquier valor que no sea uno de estos dos, y un `match` sin `default`
 * sobre este tipo falla en tiempo de análisis si se añade un tercer kind
 * sin actualizar todos los switches — preferible a que un string mal
 * escrito ("Card" vs "card") se cuele silenciosamente en producción.
 */
enum PaymentMethodKind: string
{
    case Card = 'card';
    case Yape = 'yape';
}
