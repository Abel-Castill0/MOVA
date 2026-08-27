<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\Student;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * P1 — prueba de comportamiento real (no solo del guard de la migración,
 * ver ClassesStatusEnumMigrationDriftGuardTest.php) para
 * 2026_08_27_000003_add_check_constraint_to_classes_status_for_sqlite.php:
 * usa la MISMA base de datos SQLite que corre toda la suite (RefreshDatabase,
 * sin mocks) para demostrar que la ausencia histórica de un CHECK sobre
 * `classes.status` ya no existe.
 *
 * Antes de esta migración, la segunda mitad de este archivo
 * (`test_an_invalid_status_is_rejected_by_the_database_itself`) habría
 * pasado un valor inválido directo a la base de datos sin que nada lo
 * detuviera — exactamente el riesgo que motivó reclasificar este hallazgo
 * a P1 en docs/MOVA_DESIGN_AUDIT_FINAL.md.
 */
class ClassesStatusCheckConstraintTest extends TestCase
{
    use RefreshDatabase;

    private TeacherProfile $profile;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');
        $this->profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_verified' => true,
            'credits_available' => 10,
            'credits_reserved' => 0,
        ]);

        $parent = User::factory()->create();
        $parent->assignRole('parent');
        $this->student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Alumno',
            'last_name' => 'Prueba',
            'grade_level' => 'secundaria',
        ]);
    }

    /**
     * @dataProvider canonicalStatusesProvider
     */
    public function test_every_canonical_status_is_accepted_by_the_database(string $status): void
    {
        $lesson = Lesson::create([
            'teacher_profile_id' => $this->profile->id,
            'student_id' => $this->student->id,
            'start_time' => now()->addDay(),
            'duration_minutes' => 60,
            'price_frozen_pen' => 20.00,
            'status' => $status,
        ]);

        $this->assertSame($status, $lesson->fresh()->status);
    }

    public static function canonicalStatusesProvider(): array
    {
        return [
            'scheduled' => ['scheduled'],
            'paid' => ['paid'],
            'pending_parent_confirmation' => ['pending_parent_confirmation'],
            'completed' => ['completed'],
            'cancelled' => ['cancelled'],
            'needs_admin_review' => ['needs_admin_review'],
        ];
    }

    public function test_an_invalid_status_is_rejected_by_the_database_itself(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/CHECK constraint failed/');

        Lesson::create([
            'teacher_profile_id' => $this->profile->id,
            'student_id' => $this->student->id,
            'start_time' => now()->addDay(),
            'duration_minutes' => 60,
            'price_frozen_pen' => 20.00,
            'status' => 'not_a_real_status',
        ]);
    }

    /**
     * `in_progress` fue eliminado deliberadamente del contrato canónico
     * (2026_08_24_000002_remove_in_progress_from_classes_status_enum.php,
     * confirmado sin escritor real en ningún lugar del código). Si alguien
     * lo reintrodujera por error, el CHECK debe rechazarlo igual que
     * cualquier otro valor fuera del contrato.
     */
    public function test_the_removed_in_progress_status_is_also_rejected(): void
    {
        $this->expectException(QueryException::class);

        Lesson::create([
            'teacher_profile_id' => $this->profile->id,
            'student_id' => $this->student->id,
            'start_time' => now()->addDay(),
            'duration_minutes' => 60,
            'price_frozen_pen' => 20.00,
            'status' => 'in_progress',
        ]);
    }
}
