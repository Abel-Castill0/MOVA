<?php

namespace Tests\Unit;

use App\Payment\MercadoPagoPaymentProvider;
use PHPUnit\Framework\TestCase as BaseTestCase;
use ReflectionMethod;

/**
 * Serializacion de `transaction_amount` para Yape (caso 2072, ticket WCS-55456).
 *
 * La rama fix/yape-amount-format cambia amountMinorToApiNumber() de float
 * (round(minor/100, 2)) a string con 2 decimales (number_format(..., 2, '.', '')).
 * Este test fija el contrato de salida: "1.00", "15.50", etc.
 *
 * Notas de diseno:
 *  - Extiende PHPUnit\Framework\TestCase directo (Test unitario puro): el metodo
 *    es estatico en su logica y no necesita contenedor ni base de datos. Es la
 *    primera suite "Unit" del proyecto (phpunit.xml la re-declara junto a este
 *    archivo; la nota historica pedia exactamente eso).
 *  - amountMinorToApiNumber() es PRIVATE, asi que se invoca por reflexion. La
 *    alternativa (hacerla publica) ampliaria la superficie de la clase solo para
 *    testear: no se hizo.
 */
class MercadoPagoAmountTest extends BaseTestCase
{
    private function amountMinorToApiNumber(int $amountMinor): string
    {
        $instance = (new \ReflectionClass(MercadoPagoPaymentProvider::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(MercadoPagoPaymentProvider::class, 'amountMinorToApiNumber');
        $method->setAccessible(true);

        return $method->invoke($instance, $amountMinor);
    }

    public function test_one_pen_is_serialized_as_string_with_two_decimals(): void
    {
        $this->assertSame('1.00', $this->amountMinorToApiNumber(100));
    }

    public function test_fifteen_fifty_pen_is_serialized_as_string_with_two_decimals(): void
    {
        $this->assertSame('15.50', $this->amountMinorToApiNumber(1550));
    }

    public function test_result_is_a_string_not_a_float(): void
    {
        $this->assertIsString($this->amountMinorToApiNumber(100));
    }
}
