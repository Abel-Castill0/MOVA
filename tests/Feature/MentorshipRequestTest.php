<?php

namespace Tests\Feature;

use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Punto de entrada del checkbox "Busco acompañamiento continuo" en
 * ClassRequests/Create.vue (HANDOFF_FINAL.md §21) — ClassRequest.is_mentorship
 * ya podía activarse sin ClassOffer (columna $fillable, sin gate en store());
 * el gate real de cupos vive en LessonController::store()/cancel() y ya
 * funcionaba para el flujo de ofertas. Estos tests cubren el mismo código
 * para el flujo de solicitud abierta, sin ClassOffer de por medio, y cierran
 * un hueco real: el release de cupo en cancel() (no admin) no tenía test.
 */
class MentorshipRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_an_open_request_can_be_flagged_as_mentorship_without_an_offer(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        $subject = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'todos']);

        $this->actingAs($parent)->post(route('class-requests.store'), [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'is_mentorship' => true,
            'help_needed' => 'Necesita seguimiento continuo.',
        ])->assertRedirect(route('class-requests.index'));

        $request = ClassRequest::latest()->first();
        $this->assertTrue((bool) $request->is_mentorship);
        $this->assertNull($request->class_offer_id);
    }

    /**
     * assertStatus(422) era la aserción original — sobrevivía igual con el
     * abort_unless() crudo que este mismo hallazgo de sesión acaba de
     * corregir a ValidationException real (LessonController::store()): un
     * abort() también produce 422 si se le pasa ese código, pero como
     * HttpException plano, no como error de validación real — Inertia nunca
     * lo traduce a form.errors, así que esta prueba nunca demostró que el
     * profesor viera el mensaje. Corregido a assertSessionHasErrors() con el
     * texto exacto (confirmado con revert-confirm-restore antes de fijar
     * esta aserción, junto con el resto del hallazgo en LessonController).
     */
    public function test_teacher_without_available_slots_cannot_accept_a_mentorship_request(): void
    {
        [$teacher, $profile, $subject] = $this->teacherWithSubject(5);
        $profile->update(['mentorship_slots_total' => 1, 'mentorship_slots_taken' => 1]);
        $request = $this->mentorshipRequest($subject);

        $this->actingAs($teacher)->post(route('lessons.store'), [
            'class_request_id' => $request->id,
            'start_time' => now()->addDay()->toDateTimeString(),
            'duration_minutes' => 60,
        ])->assertRedirect()->assertSessionHasErrors([
            'accept' => 'Tienes la agenda llena para acompañamiento continuo — no puedes aceptar esta solicitud por ahora.',
        ]);

        $this->assertSame(0, Lesson::count());
        // La solicitud sigue abierta — el rechazo no la consume.
        $this->assertSame('open', $request->fresh()->status);
        // Los créditos NO se tocan: el gate de mentoría corta antes de reservar.
        $this->assertSame(5, $profile->fresh()->credits_available);
    }

    public function test_teacher_with_available_slots_accepts_and_slot_increments(): void
    {
        [$teacher, $profile, $subject] = $this->teacherWithSubject(5);
        $profile->update(['mentorship_slots_total' => 2, 'mentorship_slots_taken' => 0]);
        $request = $this->mentorshipRequest($subject);

        $this->actingAs($teacher)->post(route('lessons.store'), [
            'class_request_id' => $request->id,
            'start_time' => now()->addDay()->toDateTimeString(),
            'duration_minutes' => 60,
        ])->assertRedirect(route('teacher.lessons'));

        $lesson = Lesson::firstOrFail();
        $this->assertSame('scheduled', $lesson->status);
        $this->assertSame(1, $profile->fresh()->mentorship_slots_taken);
        // El gate de mentoría no reemplaza el de créditos: ambos corren.
        $this->assertSame(4, $profile->fresh()->credits_available);
    }

    public function test_non_mentorship_request_never_touches_slot_counters(): void
    {
        [$teacher, $profile, $subject] = $this->teacherWithSubject(5);
        $profile->update(['mentorship_slots_total' => 0, 'mentorship_slots_taken' => 0]);
        [, , $request] = $this->parentRequest($subject, 'open');

        $this->actingAs($teacher)->post(route('lessons.store'), [
            'class_request_id' => $request->id,
            'start_time' => now()->addDay()->toDateTimeString(),
            'duration_minutes' => 60,
        ])->assertRedirect(route('teacher.lessons'));

        $this->assertSame(0, $profile->fresh()->mentorship_slots_taken);
    }

    public function test_cancelling_a_scheduled_mentorship_lesson_releases_the_slot(): void
    {
        [$teacher, $profile, $subject] = $this->teacherWithSubject(5);
        $profile->update(['mentorship_slots_total' => 1, 'mentorship_slots_taken' => 0]);
        $request = $this->mentorshipRequest($subject);

        $this->actingAs($teacher)->post(route('lessons.store'), [
            'class_request_id' => $request->id,
            'start_time' => now()->addDay()->toDateTimeString(),
            'duration_minutes' => 60,
        ])->assertRedirect(route('teacher.lessons'));

        $this->assertSame(1, $profile->fresh()->mentorship_slots_taken);
        $lesson = Lesson::firstOrFail();

        $this->actingAs($teacher)->post(route('lessons.cancel', $lesson), [
            'reason' => 'Cambio de disponibilidad',
        ])->assertRedirect();

        $this->assertSame(0, $profile->fresh()->mentorship_slots_taken);
        $this->assertSame(5, $profile->fresh()->credits_available);
    }

    private function teacherWithSubject(int $availableCredits = 0): array
    {
        $user = User::factory()->create();
        $user->assignRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $user->id,
            'is_verified' => true,
            'hourly_rate' => 20,
            'credits_available' => $availableCredits,
        ]);
        $subject = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'todos']);
        $profile->subjects()->attach($subject->id);

        return [$user, $profile, $subject];
    }

    private function parentWithStudent(): array
    {
        $parent = User::factory()->create();
        $parent->assignRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Alumno',
            'last_name' => 'Prueba',
            'grade_level' => 'secundaria',
        ]);

        return [$parent, $student];
    }

    private function parentRequest(Subject $subject, string $status): array
    {
        [$parent, $student] = $this->parentWithStudent();
        $request = ClassRequest::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Necesita reforzar el tema.',
            'status' => $status,
        ]);

        return [$parent, $student, $request];
    }

    private function mentorshipRequest(Subject $subject): ClassRequest
    {
        [, , $request] = $this->parentRequest($subject, 'open');
        $request->is_mentorship = true;
        $request->save();

        return $request;
    }
}
