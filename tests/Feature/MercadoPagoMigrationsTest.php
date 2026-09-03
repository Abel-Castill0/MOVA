<?php

namespace Tests\Feature;

use App\Models\PaymentOrder;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Payment\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
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
 *
 * MOVA MYSQL QA GATE: #[RunClassInSeparateProcess] es obligatorio aquí, no
 * cosmético. up()/down() ejecutan DDL real (Schema::table()/dropColumn()) —
 * bajo MySQL, una sentencia DDL hace COMMIT implícito de cualquier
 * transacción abierta (SQLite no tiene este problema de la misma forma).
 * RefreshDatabase envuelve cada test en una transacción que revierte en el
 * tearDown; si el DDL de un test de esta clase corriera en el MISMO proceso
 * que otros tests con RefreshDatabase, ese commit implícito rompe el
 * tracking de nivel de transacción de Laravel para TODO test que corra
 * después en ese proceso — sus inserts dejan de revertirse y quedan
 * permanentes, sin ningún error visible (confirmado en vivo contra mova_qa:
 * ver docs/SESSION_HANDOFF.md). Aislar la clase en su propio proceso PHP
 * hace que el commit implícito no pueda contaminar transacciones de otras
 * clases, sin depender de que alguien recuerde el orden de ejecución.
 */
#[RunClassInSeparateProcess]
class MercadoPagoMigrationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * MOVA MYSQL QA GATE: bajo MySQL, el DDL de down()/up() que corre DENTRO
     * de cada test hace COMMIT implícito de la transacción que RefreshDatabase
     * abrió para ese test — cualquier fila creada ANTES de ese DDL (el
     * User/TeacherProfile/RechargeRequest/PaymentOrder de cada test) queda
     * comprometida de verdad en mova_qa; el rollback normal de tearDown() ya
     * no tiene nada que revertir. #[RunClassInSeparateProcess] evita que ese
     * commit implícito rompa el tracking de transacciones de OTRAS clases,
     * pero no borra lo que esta clase deja en la base de datos compartida —
     * así que se limpia explícitamente aquí, en orden compatible con las FKs
     * (PaymentOrder -> RechargeRequest -> TeacherProfile -> User). Bajo
     * SQLite :memory: este bloque es un no-op inofensivo (el rollback normal
     * ya dejó las tablas vacías).
     */
    protected function tearDown(): void
    {
        PaymentOrder::query()->delete();
        RechargeRequest::query()->delete();
        TeacherProfile::query()->delete();
        User::query()->delete();

        parent::tearDown();
    }

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
