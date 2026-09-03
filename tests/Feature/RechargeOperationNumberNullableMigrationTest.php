<?php

namespace Tests\Feature;

use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * P1 (MOVA Yape Checkout Pre-Card Hardening) —
 * 2026_09_02_000001_make_recharge_operation_number_nullable.php.
 *
 * Corre contra la base de datos de test ya migrada (RefreshDatabase, sin
 * migrate:fresh dentro del test) invocando up()/down() directamente, mismo
 * patrón que MercadoPagoMigrationsTest.
 */
class RechargeOperationNumberNullableMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_mercadopago_recharge_can_be_created_with_null_operation_number(): void
    {
        [, $profile] = $this->teacher();

        $recharge = RechargeRequest::create([
            'teacher_profile_id' => $profile->id,
            'package_code' => 'inicio',
            'package_name' => 'Inicio',
            'credits' => 5,
            'amount_pen' => '10.00',
            'payment_method' => 'mercadopago',
            'operation_number' => null,
            'operation_number_normalized' => null,
            'status' => 'pending',
        ]);

        $this->assertNull($recharge->fresh()->operation_number);
        $this->assertNull($recharge->fresh()->operation_number_normalized);
    }

    /**
     * Dos recargas mercadopago (ambas operation_number_normalized=NULL) no
     * chocan contra el índice único compuesto
     * (payment_method, operation_number_normalized) — MySQL y SQLite
     * excluyen NULL de la comprobación de unicidad.
     */
    public function test_two_mercadopago_recharges_with_null_operation_number_do_not_collide(): void
    {
        [, $profile] = $this->teacher();

        $this->recharge($profile, null);
        $second = $this->recharge($profile, null);

        $this->assertNotNull($second->id);
        $this->assertDatabaseCount('recharge_requests', 2);
    }

    /**
     * El flujo manual sigue exigiendo un operation_number real — esto lo
     * decide la validación de CreditController::storeRecharge()
     * ('required'), no la restricción de la base de datos (ver
     * MonetizationIntegrityTest para la cobertura HTTP completa). Aquí solo
     * se confirma que la columna, aunque ahora nullable, sigue aceptando y
     * preservando un valor real sin degradarlo.
     */
    public function test_a_manual_recharge_still_stores_a_real_operation_number(): void
    {
        [, $profile] = $this->teacher();

        $recharge = $this->recharge($profile, 'OP12345678', 'yape');

        $this->assertSame('OP12345678', $recharge->fresh()->operation_number);
    }

    public function test_rollback_aborts_when_a_null_operation_number_mercadopago_recharge_exists(): void
    {
        [, $profile] = $this->teacher();
        $this->recharge($profile, null);

        $migration = $this->loadMigration();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/recarga\(s\) mercadopago con operation_number NULL/');

        $migration->down();
    }

    public function test_rollback_succeeds_when_no_null_operation_number_recharges_exist(): void
    {
        $migration = $this->loadMigration();

        // Sin filas mercadopago con operation_number NULL (base de test
        // limpia) — el rollback debe completar sin lanzar, demostrando que
        // la migración es reversible de verdad, no solo "hacia adelante".
        $migration->down();
        $migration->up(); // deja la conexión de test como estaba para el resto de la suite

        $this->assertTrue(true);
    }

    // ---- helpers ---------------------------------------------------------

    private function loadMigration(): object
    {
        return require database_path('migrations/2026_09_02_000001_make_recharge_operation_number_nullable.php');
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

    private function recharge(TeacherProfile $profile, ?string $operation, string $paymentMethod = 'mercadopago'): RechargeRequest
    {
        return RechargeRequest::create([
            'teacher_profile_id' => $profile->id,
            'package_code' => 'inicio',
            'package_name' => 'Inicio',
            'credits' => 5,
            'amount_pen' => '10.00',
            'payment_method' => $paymentMethod,
            'operation_number' => $operation,
            'operation_number_normalized' => $operation,
            'status' => 'pending',
        ]);
    }
}
