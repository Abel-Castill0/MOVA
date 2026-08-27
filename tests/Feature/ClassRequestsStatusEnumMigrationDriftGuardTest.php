<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * 2026_08_27_000002_widen_class_requests_status_enum_for_sqlite.php es un
 * no-op en MySQL solo PORQUE asume que 2026_07_08_000001 ya amplió el ENUM
 * para incluir 'teacher_rejected'. Un no-op silencioso que resulta estar
 * equivocado (esa migración no corrió, o el schema real difiere por
 * cualquier motivo) es indistinguible de un éxito hasta que producción
 * falla con el mismo bug que esta migración existe para arreglar — así
 * que la migración verifica el ENUM real antes de no hacer nada, y falla
 * ruidosamente si la invariante no se cumple.
 *
 * No usa RefreshDatabase ni MySQL real: esto no prueba una migración
 * completa, prueba específicamente el guard — con un ENUM real (no
 * lanza) y con uno simulado sin 'teacher_rejected' (sí lanza), vía un
 * mock de la fachada DB. El resto del comportamiento de esta migración
 * (up()/down()/rollback safety en SQLite) ya está cubierto por
 * TeacherRejectClassRequestTest.php.
 */
class ClassRequestsStatusEnumMigrationDriftGuardTest extends TestCase
{
    public function test_it_does_not_throw_when_the_real_enum_already_has_teacher_rejected(): void
    {
        DB::shouldReceive('selectOne')
            ->once()
            ->with("SHOW COLUMNS FROM class_requests LIKE 'status'")
            ->andReturn((object) ['Type' => "enum('pending_parent_approval','open','accepted','rejected','teacher_rejected','completed')"]);

        $this->callGuard();

        $this->assertTrue(true, 'Llegar aquí sin excepción es el resultado esperado.');
    }

    public function test_it_throws_loudly_when_the_real_enum_is_missing_teacher_rejected(): void
    {
        DB::shouldReceive('selectOne')
            ->once()
            ->with("SHOW COLUMNS FROM class_requests LIKE 'status'")
            ->andReturn((object) ['Type' => "enum('pending_parent_approval','open','accepted','rejected','completed')"]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Drift de schema detectado');

        $this->callGuard();
    }

    public function test_it_throws_loudly_when_the_status_column_cannot_be_found_at_all(): void
    {
        DB::shouldReceive('selectOne')
            ->once()
            ->with("SHOW COLUMNS FROM class_requests LIKE 'status'")
            ->andReturn(null);

        $this->expectException(RuntimeException::class);

        $this->callGuard();
    }

    private function callGuard(): void
    {
        $migration = require database_path('migrations/2026_08_27_000002_widen_class_requests_status_enum_for_sqlite.php');

        $method = new ReflectionMethod($migration, 'assertMysqlEnumAlreadyIncludesTeacherRejected');
        $method->setAccessible(true);
        $method->invoke($migration);
    }
}
