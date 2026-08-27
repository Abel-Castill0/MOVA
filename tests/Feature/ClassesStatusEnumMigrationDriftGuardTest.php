<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * Mismo patrón que ClassRequestsStatusEnumMigrationDriftGuardTest.php, para
 * el guard de MySQL de
 * 2026_08_27_000003_add_check_constraint_to_classes_status_for_sqlite.php.
 *
 * Esta guard es más estricta que la de class_requests: en vez de comprobar
 * solo "¿está presente el valor que me importa?", compara el conjunto
 * COMPLETO del ENUM real contra el contrato canónico (orden-independiente).
 * Eso detecta tanto un estado canónico ausente como un estado histórico
 * (p. ej. un `in_progress` que debería haberse limpiado) que sigue
 * presente en una instalación concreta y ya no debería estarlo.
 */
class ClassesStatusEnumMigrationDriftGuardTest extends TestCase
{
    public function test_it_does_not_throw_when_the_real_enum_matches_the_canonical_set_exactly(): void
    {
        DB::shouldReceive('selectOne')
            ->once()
            ->with("SHOW COLUMNS FROM classes LIKE 'status'")
            ->andReturn((object) ['Type' => "enum('scheduled','paid','pending_parent_confirmation','completed','cancelled','needs_admin_review')"]);

        $this->callGuard();

        $this->assertTrue(true, 'Llegar aquí sin excepción es el resultado esperado.');
    }

    public function test_it_does_not_throw_when_the_enum_order_differs_but_the_set_is_the_same(): void
    {
        DB::shouldReceive('selectOne')
            ->once()
            ->with("SHOW COLUMNS FROM classes LIKE 'status'")
            ->andReturn((object) ['Type' => "enum('needs_admin_review','cancelled','completed','pending_parent_confirmation','paid','scheduled')"]);

        $this->callGuard();

        $this->assertTrue(true, 'El orden textual del ENUM no debe importar, solo el conjunto.');
    }

    public function test_it_throws_when_a_canonical_status_is_missing(): void
    {
        DB::shouldReceive('selectOne')
            ->once()
            ->with("SHOW COLUMNS FROM classes LIKE 'status'")
            ->andReturn((object) ['Type' => "enum('scheduled','paid','pending_parent_confirmation','completed','cancelled')"]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Drift de schema detectado');

        $this->callGuard();
    }

    public function test_it_throws_when_a_stale_extra_status_like_in_progress_is_still_present(): void
    {
        DB::shouldReceive('selectOne')
            ->once()
            ->with("SHOW COLUMNS FROM classes LIKE 'status'")
            ->andReturn((object) ['Type' => "enum('scheduled','in_progress','paid','pending_parent_confirmation','completed','cancelled','needs_admin_review')"]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Drift de schema detectado');

        $this->callGuard();
    }

    public function test_it_throws_when_the_status_column_cannot_be_found_at_all(): void
    {
        DB::shouldReceive('selectOne')
            ->once()
            ->with("SHOW COLUMNS FROM classes LIKE 'status'")
            ->andReturn(null);

        $this->expectException(RuntimeException::class);

        $this->callGuard();
    }

    private function callGuard(): void
    {
        $migration = require database_path('migrations/2026_08_27_000003_add_check_constraint_to_classes_status_for_sqlite.php');

        $method = new ReflectionMethod($migration, 'assertMysqlEnumMatchesCanonicalStatuses');
        $method->setAccessible(true);
        $method->invoke($migration);
    }
}
