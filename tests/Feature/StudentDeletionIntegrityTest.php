<?php

namespace Tests\Feature;

use App\Models\ClassRequest;
use App\Models\CreditTransaction;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * F-18 — Hallazgo NUEVO de la reauditoría post-Fase 3.
 *
 * Al buscar caminos de borrado que se saltaran el guard de User (los bulk
 * deletes que Model::deleting no intercepta) apareció otro problema, un nivel
 * más abajo: `StudentController::destroy()` hacía `$student->delete()` físico
 * sin ninguna comprobación. Con historial académico eso daba:
 *   - MySQL: excepción cruda de FK (classes.student_id es RESTRICT) -> 500.
 *   - SQLite (tests): CASCADE -> las clases del alumno se borraban de verdad.
 *
 * Ninguno de los dos es aceptable: las clases respaldan movimientos del ledger.
 */
class StudentDeletionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_deleting_a_student_with_lessons_preserves_the_lesson_history(): void
    {
        [$parent, $student, $lesson] = $this->studentWithLesson();

        $this->actingAs($parent)
            ->delete(route('students.destroy', $student))
            ->assertRedirect(route('students.index'));

        // La clase sobrevive y sigue apuntando a una fila existente.
        $this->assertDatabaseHas('classes', ['id' => $lesson->id, 'student_id' => $student->id]);
        $this->assertNotNull(Student::withTrashed()->find($student->id));
    }

    public function test_deleting_a_student_with_lessons_preserves_the_ledger(): void
    {
        [$parent, $student, $lesson] = $this->studentWithLesson();
        $ledgerCount = CreditTransaction::where('lesson_id', $lesson->id)->count();

        $this->actingAs($parent)->delete(route('students.destroy', $student));

        $this->assertSame($ledgerCount, CreditTransaction::where('lesson_id', $lesson->id)->count());
    }

    public function test_a_deleted_student_disappears_from_the_parents_list(): void
    {
        [$parent, $student] = $this->studentWithLesson();

        $this->actingAs($parent)->delete(route('students.destroy', $student));

        // El soft delete cumple la promesa de la UI: el alumno ya no se ve,
        // aunque la fila siga existiendo para sostener el historial.
        $this->assertNull(Student::find($student->id));
        $this->assertSame(0, $parent->students()->count());
    }

    public function test_the_minors_personal_data_is_scrubbed_when_the_row_must_survive(): void
    {
        [$parent, $student] = $this->studentWithLesson();

        $this->actingAs($parent)->delete(route('students.destroy', $student));

        // La fila sobrevive por integridad referencial, pero no debe seguir
        // conteniendo el nombre, la fecha de nacimiento ni el colegio del menor.
        $survivor = Student::withTrashed()->find($student->id);
        $this->assertSame('Estudiante', $survivor->first_name);
        $this->assertStringNotContainsString('Pedro', $survivor->full_name);
        $this->assertNull($survivor->birth_date);
        $this->assertNull($survivor->school);
    }

    public function test_a_student_without_history_is_deleted_cleanly(): void
    {
        $parent = $this->userWithRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Pedro',
            'last_name' => 'Sin Historial',
            'grade_level' => 'secundaria',
        ]);

        $this->actingAs($parent)->delete(route('students.destroy', $student));

        $this->assertNull(Student::find($student->id));
        // Sin historial no hay nada que preservar, así que tampoco hace falta
        // anonimizar: la baja es limpia.
        $this->assertSame('Pedro', Student::withTrashed()->find($student->id)->first_name);
    }

    public function test_force_deleting_a_student_with_history_is_refused(): void
    {
        // El soft delete cubre el camino normal; este guard cubre el único que
        // todavía podría destruir historial.
        [, $student] = $this->studentWithLesson();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no se puede eliminar físicamente/i');

        $student->forceDelete();
    }

    public function test_force_deleting_a_student_without_history_is_allowed(): void
    {
        $parent = $this->userWithRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Pedro',
            'last_name' => 'Sin Historial',
            'grade_level' => 'secundaria',
        ]);

        $student->forceDelete();

        $this->assertNull(Student::withTrashed()->find($student->id));
    }

    public function test_a_student_with_only_class_requests_also_keeps_its_history(): void
    {
        $parent = $this->userWithRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Pedro',
            'last_name' => 'Solo Solicitudes',
            'grade_level' => 'secundaria',
        ]);
        $subject = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'secundaria']);
        $request = ClassRequest::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Necesita reforzar el tema.',
            'status' => 'open',
        ]);

        $this->actingAs($parent)->delete(route('students.destroy', $student));

        $this->assertDatabaseHas('class_requests', ['id' => $request->id, 'student_id' => $student->id]);
    }

    // ── F-18 (Fase 4): regresión del soft delete ─────────────────────────

    public function test_historical_lessons_still_resolve_their_student_after_deletion(): void
    {
        // REGRESIÓN REAL detectada en la Fase 4: al añadir SoftDeletes,
        // $lesson->student devolvía NULL en cuanto el padre daba de baja al
        // alumno. Las clases históricas perdían su alumno en la interfaz.
        [$parent, $student, $lesson] = $this->studentWithLesson();

        $this->actingAs($parent)->delete(route('students.destroy', $student));

        $this->assertNotNull(
            $lesson->fresh()->student,
            'Una clase histórica debe seguir resolviendo su alumno aunque esté dado de baja.'
        );
    }

    public function test_the_parent_is_still_reachable_for_notifications_after_deletion(): void
    {
        // La consecuencia MÁS grave de la regresión: el patrón
        // `$lesson->student?->parent?->notify(...)` —usado en AdminController
        // (cancelar / force-refund) y LessonController::cancel()— dejaba de
        // encontrar destinatario, así que el padre dejaba de recibir avisos
        // financieros SIN ningún error visible.
        [$parent, $student, $lesson] = $this->studentWithLesson();

        $this->actingAs($parent)->delete(route('students.destroy', $student));

        $this->assertNotNull($lesson->fresh()->student?->parent, 'El padre debe seguir siendo notificable.');
        $this->assertSame($parent->id, $lesson->fresh()->student->parent->id);
    }

    public function test_the_teacher_still_sees_the_historical_lesson_after_the_student_is_deleted(): void
    {
        [$parent, $student, $lesson] = $this->studentWithLesson();
        $teacher = $lesson->teacherProfile->user;
        $teacher->assignRole('teacher');

        $this->actingAs($parent)->delete(route('students.destroy', $student));

        $this->actingAs($teacher)
            ->get(route('teacher.lessons'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('lessons', 1));
    }

    public function test_class_requests_and_reports_also_keep_resolving_their_student(): void
    {
        [$parent, $student, $lesson] = $this->studentWithLesson();
        $subject = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'secundaria']);
        $request = ClassRequest::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Necesita reforzar el tema.',
            'status' => 'open',
        ]);

        $this->actingAs($parent)->delete(route('students.destroy', $student));

        $this->assertNotNull($request->fresh()->student, 'ClassRequest::student() necesita withTrashed().');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['password' => 'password']);
        $user->assignRole($role);

        return $user;
    }

    /** @return array{0: User, 1: Student, 2: Lesson} */
    private function studentWithLesson(): array
    {
        $teacher = $this->userWithRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_verified' => true,
            'credits_available' => 0,
            'credits_reserved' => 1,
        ]);

        $parent = $this->userWithRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Pedro',
            'last_name' => 'Con Historial',
            'birth_date' => '2012-05-10',
            'school' => 'Colegio de Prueba',
            'grade_level' => 'secundaria',
        ]);

        $lesson = Lesson::create([
            'teacher_profile_id' => $profile->id,
            'student_id' => $student->id,
            'start_time' => now()->subDays(5),
            'duration_minutes' => 60,
            'status' => 'completed',
        ]);

        CreditTransaction::create([
            'teacher_profile_id' => $profile->id,
            'lesson_id' => $lesson->id,
            'idempotency_key' => "lesson:{$lesson->id}:reservation",
            'type' => 'reservation',
            'amount' => 1,
            'description' => 'Reserva por aceptación de clase',
        ]);

        return [$parent, $student, $lesson];
    }
}
