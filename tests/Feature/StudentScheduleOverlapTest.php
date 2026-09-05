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
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * §13 — Un alumno no puede tener dos clases solapadas.
 *
 * Antes solo se protegía la agenda del PROFESOR
 * (`LessonController::hasScheduleOverlap()` filtraba por `teacher_profile_id`),
 * así que dos profesores distintos podían agendar dos clases simultáneas para el
 * mismo menor. El padre lo descubría al recibir dos recordatorios para la misma
 * hora, con dos créditos ya reservados (docs/MOVA_SYSTEM_MAP.md R-14).
 *
 * Es un problema de MENOR: el alumno no tiene cuenta y no puede avisar de nada.
 */
class StudentScheduleOverlapTest extends TestCase
{
    use RefreshDatabase;

    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role);
        }

        $this->subject = Subject::firstOrCreateByName('Matemática');
    }

    private function teacher(int $credits = 10): array
    {
        $user = User::factory()->create();
        $user->assignRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $user->id,
            'hourly_rate' => 20,
            'credits_available' => $credits,
            'is_verified' => true,
        ]);
        $profile->subjects()->sync([$this->subject->id => ['specific_rate' => null]]);

        return [$user, $profile];
    }

    private function parentWithStudent(): array
    {
        $parent = User::factory()->create();
        $parent->assignRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'grade_level' => 'secundaria',
        ]);

        return [$parent, $student];
    }

    private function openRequest(Student $student): ClassRequest
    {
        return ClassRequest::create([
            'student_id' => $student->id,
            'subject_id' => $this->subject->id,
            'help_needed' => 'Necesita apoyo con álgebra.',
            'status' => 'open',
        ]);
    }

    private function scheduledLessonFor(Student $student, TeacherProfile $profile, Carbon $start, int $minutes = 60): Lesson
    {
        $lesson = Lesson::create([
            'teacher_profile_id' => $profile->id,
            'student_id' => $student->id,
            'class_request_id' => $this->openRequest($student)->id,
            'start_time' => $start,
            'duration_minutes' => $minutes,
            'status' => 'scheduled',
        ]);

        CreditTransaction::create([
            'teacher_profile_id' => $profile->id,
            'lesson_id' => $lesson->id,
            'idempotency_key' => "lesson:{$lesson->id}:reservation",
            'type' => 'reservation',
            'amount' => 1,
            'description' => 'Reserva por aceptación de clase',
        ]);

        return $lesson;
    }

    // ── Creación ─────────────────────────────────────────────────────────

    public function test_a_second_teacher_cannot_book_the_same_student_at_the_same_time(): void
    {
        [, $student] = $this->parentWithStudent();
        [, $profileA] = $this->teacher();
        [$teacherB, $profileB] = $this->teacher();

        $start = now()->addDays(3)->setTime(16, 0);
        $this->scheduledLessonFor($student, $profileA, $start);

        $request = $this->openRequest($student);

        $this->actingAs($teacherB)
            ->post(route('lessons.store'), [
                'class_request_id' => $request->id,
                'start_time' => $start->toIso8601String(),
                'duration_minutes' => 60,
            ])
            ->assertSessionHasErrors('start_time');

        $this->assertSame(1, Lesson::where('student_id', $student->id)->count());
        $this->assertSame(10, $profileB->fresh()->credits_available, 'No se reserva crédito por una clase que no se creó.');
    }

    public function test_a_partial_overlap_is_also_rejected(): void
    {
        [, $student] = $this->parentWithStudent();
        [, $profileA] = $this->teacher();
        [$teacherB] = $this->teacher();

        // Existente 16:00–17:00; nueva 16:30–17:30.
        $this->scheduledLessonFor($student, $profileA, now()->addDays(3)->setTime(16, 0));
        $request = $this->openRequest($student);

        $this->actingAs($teacherB)
            ->post(route('lessons.store'), [
                'class_request_id' => $request->id,
                'start_time' => now()->addDays(3)->setTime(16, 30)->toIso8601String(),
                'duration_minutes' => 60,
            ])
            ->assertSessionHasErrors('start_time');
    }

    public function test_the_error_names_the_student_not_the_teacher(): void
    {
        [, $student] = $this->parentWithStudent();
        [, $profileA] = $this->teacher();
        [$teacherB] = $this->teacher();

        $start = now()->addDays(3)->setTime(16, 0);
        $this->scheduledLessonFor($student, $profileA, $start);
        $request = $this->openRequest($student);

        $response = $this->actingAs($teacherB)->post(route('lessons.store'), [
            'class_request_id' => $request->id,
            'start_time' => $start->toIso8601String(),
            'duration_minutes' => 60,
        ]);

        $errors = session('errors')->get('start_time');
        $this->assertStringContainsString('alumno', $errors[0], 'Decir "ya tienes una clase" sería mentira: la agenda ocupada es la del alumno.');
    }

    // ── Casos que NO deben bloquear ──────────────────────────────────────

    public function test_adjacent_classes_are_allowed(): void
    {
        [, $student] = $this->parentWithStudent();
        [, $profileA] = $this->teacher();
        [$teacherB] = $this->teacher();

        // Existente 16:00–17:00; nueva empieza exactamente a las 17:00.
        $this->scheduledLessonFor($student, $profileA, now()->addDays(3)->setTime(16, 0));
        $request = $this->openRequest($student);

        $this->actingAs($teacherB)
            ->post(route('lessons.store'), [
                'class_request_id' => $request->id,
                'start_time' => now()->addDays(3)->setTime(17, 0)->toIso8601String(),
                'duration_minutes' => 60,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Lesson::where('student_id', $student->id)->count());
    }

    /**
     * Una clase cancelada LIBERA la franja: ya no ocurre.
     */
    public function test_a_cancelled_class_does_not_block_the_slot(): void
    {
        [, $student] = $this->parentWithStudent();
        [, $profileA] = $this->teacher();
        [$teacherB] = $this->teacher();

        $start = now()->addDays(3)->setTime(16, 0);
        $existing = $this->scheduledLessonFor($student, $profileA, $start);
        $existing->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $request = $this->openRequest($student);

        $this->actingAs($teacherB)
            ->post(route('lessons.store'), [
                'class_request_id' => $request->id,
                'start_time' => $start->toIso8601String(),
                'duration_minutes' => 60,
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_two_different_students_of_the_same_parent_can_share_a_slot(): void
    {
        [$parent, $studentA] = $this->parentWithStudent();
        $studentB = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Luis',
            'last_name' => 'Pérez',
            'grade_level' => 'primaria',
        ]);

        [, $profileA] = $this->teacher();
        [$teacherB] = $this->teacher();

        $start = now()->addDays(3)->setTime(16, 0);
        $this->scheduledLessonFor($studentA, $profileA, $start);

        $request = $this->openRequest($studentB);

        // Dos hermanos con dos profesores distintos a la misma hora es legítimo.
        $this->actingAs($teacherB)
            ->post(route('lessons.store'), [
                'class_request_id' => $request->id,
                'start_time' => $start->toIso8601String(),
                'duration_minutes' => 60,
            ])
            ->assertSessionHasNoErrors();
    }

    // ── Reprogramación ───────────────────────────────────────────────────

    public function test_rescheduling_into_a_slot_busy_for_the_student_is_rejected(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        [, $profileA] = $this->teacher();
        [, $profileB] = $this->teacher();

        $busy = now()->addDays(3)->setTime(16, 0);
        $this->scheduledLessonFor($student, $profileA, $busy);
        $moving = $this->scheduledLessonFor($student, $profileB, now()->addDays(4)->setTime(10, 0));

        $this->actingAs($parent)
            ->post(route('lessons.reschedule', $moving), [
                'start_time' => $busy->toIso8601String(),
            ])
            ->assertSessionHasErrors('start_time');

        $this->assertTrue(
            $moving->fresh()->start_time->equalTo(now()->addDays(4)->setTime(10, 0)->utc()),
            'La clase no debe moverse.'
        );
    }

    public function test_a_lesson_never_conflicts_with_itself_when_rescheduled(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        [, $profile] = $this->teacher();

        $lesson = $this->scheduledLessonFor($student, $profile, now()->addDays(3)->setTime(16, 0));

        $this->actingAs($parent)
            ->post(route('lessons.reschedule', $lesson), [
                'start_time' => now()->addDays(3)->setTime(18, 0)->toIso8601String(),
            ])
            ->assertSessionHasNoErrors();
    }

    // ── El chequeo del profesor sigue vigente ────────────────────────────

    public function test_the_teacher_overlap_check_still_applies(): void
    {
        [, $studentA] = $this->parentWithStudent();
        [, $studentB] = $this->parentWithStudent();
        [$teacher, $profile] = $this->teacher();

        $start = now()->addDays(3)->setTime(16, 0);
        $this->scheduledLessonFor($studentA, $profile, $start);

        $request = $this->openRequest($studentB);

        $response = $this->actingAs($teacher)->post(route('lessons.store'), [
            'class_request_id' => $request->id,
            'start_time' => $start->toIso8601String(),
            'duration_minutes' => 60,
        ]);

        $response->assertSessionHasErrors('start_time');
        $this->assertStringContainsString(
            'Ya tienes',
            session('errors')->get('start_time')[0],
            'Cuando el ocupado es el propio profesor, el mensaje debe decirlo.'
        );
    }
}
