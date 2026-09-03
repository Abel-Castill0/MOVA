<?php

namespace Tests\Feature;

use App\Models\PaymentOrder;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Payment\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * MIGRATION ROLLBACK — audita específicamente que
 * 2026_09_01_000002_add_attempt_number_to_payment_orders_table.php no
 * pueda revertir silenciosamente a UNIQUE(recharge_request_id) cuando ya
 * existen múltiples intentos por RechargeRequest (eso destruiría esa
 * trazabilidad sin avisar). Corre contra la base de datos de test ya
 * migrada (RefreshDatabase) — sin `migrate:fresh` — invocando up()/down()
 * de la migración directamente, tal como sugiere el encargo ("testear
 * rollback donde sea viable sin migrate:fresh").
 */
class MercadoPagoMigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_attempt_number_migration_rollback_aborts_when_multiple_attempts_exist(): void
    {
        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        PaymentOrder::create([
            'recharge_request_id' => $recharge->id,
            'attempt_number' => 1,
            'provider' => 'mercadopago',
            'provider_order_id' => 'PAY-1',
            'status' => 'failed',
            'amount_minor' => 1000,
            'currency' => 'PEN',
        ]);
        PaymentOrder::create([
            'recharge_request_id' => $recharge->id,
            'attempt_number' => 2,
            'provider' => 'mercadopago',
            'provider_order_id' => 'PAY-2',
            'status' => 'paid',
            'amount_minor' => 1000,
            'currency' => 'PEN',
        ]);

        $migration = $this->loadAttemptNumberMigration();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/más de un intento de pago/');

        try {
            $migration->down();
        } finally {
            // La columna debe seguir existiendo — el rollback NUNCA debe
            // aplicar cambios parciales antes de abortar.
            $this->assertTrue(Schema::hasColumn('payment_orders', 'attempt_number'));
        }
    }

    public function test_attempt_number_migration_rollback_succeeds_with_at_most_one_attempt_per_recharge(): void
    {
        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        PaymentOrder::create([
            'recharge_request_id' => $recharge->id,
            'attempt_number' => 1,
            'provider' => 'mercadopago',
            'provider_order_id' => 'PAY-1',
            'status' => 'paid',
            'amount_minor' => 1000,
            'currency' => 'PEN',
        ]);

        $migration = $this->loadAttemptNumberMigration();

        $migration->down();
        $this->assertFalse(Schema::hasColumn('payment_orders', 'attempt_number'));

        // Restaura el estado para no afectar otros tests que compartan la
        // misma conexión sqlite :memory: dentro de este proceso.
        $migration->up();
        $this->assertTrue(Schema::hasColumn('payment_orders', 'attempt_number'));
    }

    /**
     * @return object{up: callable, down: callable}
     */
    private function loadAttemptNumberMigration(): object
    {
        return require database_path('migrations/2026_09_01_000002_add_attempt_number_to_payment_orders_table.php');
    }

    private function teacher(): array
    {
        $teacher = User::factory()->create(['password' => 'password']);
        $teacher->assignRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_verified' => true,
            'credits_available' => 0,
            'credits_reserved' => 0,
        ]);

        return [$teacher, $profile];
    }

    private function recharge(TeacherProfile $profile): RechargeRequest
    {
        $operation = fake()->unique()->numerify('OP########');

        return RechargeRequest::create([
            'teacher_profile_id' => $profile->id,
            'package_code' => 'inicio',
            'package_name' => 'Inicio',
            'credits' => 5,
            'amount_pen' => '10.00',
            'payment_method' => 'mercadopago',
            'operation_number' => $operation,
            'operation_number_normalized' => $operation,
            'status' => 'pending',
        ]);
    }
}
