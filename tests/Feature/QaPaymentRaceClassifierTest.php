<?php

namespace Tests\Feature;

use App\Console\Commands\QaPaymentRace;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class QaPaymentRaceClassifierTest extends TestCase
{
    public function test_only_known_business_conflicts_are_expected_concurrent_losers(): void
    {
        $command = new class extends QaPaymentRace
        {
            public function expected(RuntimeException $exception): bool
            {
                return $this->isExpectedConcurrentRejection($exception);
            }
        };

        foreach ([
            'CulqiPaymentProvider: otro proceso ya está enviando este intento — no se duplica el cobro.',
            "CulqiPaymentProvider: RechargeRequest#42 no admite un cobro (método 'culqi', estado 'approved').",
            "CulqiPaymentProvider: RechargeRequest#42 ya tiene un intento en estado 'paid' — no se crea uno nuevo.",
            'CulqiPaymentProvider: RechargeRequest#42 tiene un intento previo con resultado incierto sin resolver.',
        ] as $message) {
            $this->assertTrue($command->expected(new RuntimeException($message)), $message);
        }

        foreach ([
            'SQLSTATE[40001]: Deadlock found when trying to get lock',
            'SQLSTATE[HY000]: Lock wait timeout exceeded',
            'CulqiPaymentProvider: timeout HTTP inesperado',
            'CulqiPaymentProvider: RechargeRequest#42 con error desconocido',
        ] as $message) {
            $this->assertFalse($command->expected(new RuntimeException($message)), $message);
        }

        $this->assertFalse($command->expected(new PDOException('SQLSTATE[40001]: deadlock')));
    }
}
