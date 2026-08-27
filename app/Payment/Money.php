<?php

namespace App\Payment;

/**
 * Conversión sol/centavos compartida por todos los providers — un solo
 * lugar para esta cuenta en vez de repetirla en cada implementación.
 * Trabaja sobre el string ya normalizado por el cast 'decimal:2' de los
 * modelos (nunca sobre un float crudo).
 */
final class Money
{
    public static function solesToMinor(string $amountPen): int
    {
        return (int) round(((float) $amountPen) * 100);
    }

    public static function minorToSoles(int $amountMinor): string
    {
        return number_format($amountMinor / 100, 2, '.', '');
    }
}
