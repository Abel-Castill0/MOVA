<?php

namespace Tests\Feature;

use App\Models\ClassOffer;
use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-4 (docs/MOVA_AUDIT_PHASE0.md, sección Q) — `specific_rate` se valida
 * y persiste en ClassOfferController, pero LessonController::store() siempre
 * usaba TeacherProfile::hourly_rate para congelar price_frozen_pen, sin leer
 * jamás la tarifa específica de la oferta. Un profesor podía configurar una
 * tarifa distinta para una oferta concreta creyendo que esa era la que se
 * cobraría, sin que tuviera ningún efecto real en el dinero.
 */
class SpecificRatePricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_class_offer_with_specific_rate_is_used_over_the_teachers_general_hourly_rate(): void
    {
        $teacher = $this->userWithRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_verified' => true,
            'credits_available' => 5,
            'credits_reserved' => 0,
            'hourly_rate' => 20,
        ]);
        $subject = Subject::create(['name' => 'Cálculo Avanzado', 'level' => 'secundaria']);
        $profile->subjects()->attach($subject->id);

        // Oferta con tarifa específica distinta de la tarifa general del perfil.
        $offer = ClassOffer::create([
            'teacher_profile_id' => $profile->id,
            'subject_id' => $subject->id,
            'title' => 'Cálculo Avanzado — nivel universitario',
            'specific_rate' => 25,
            'is_active' => true,
        ]);

        [$parent, $student] = $this->parent($subject);
        $request = ClassRequest::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'class_offer_id' => $offer->id,
            'help_needed' => 'Necesita reforzar el tema.',
            'status' => 'open',
        ]);

        $this->actingAs($teacher)->post(route('lessons.store'), [
            'class_request_id' => $request->id,
            'start_time' => now()->addDay()->toDateTimeString(),
            'duration_minutes' => 60,
        ])->assertRedirect(route('teacher.lessons'));

        $lesson = Lesson::firstOrFail();

        // 1 crédito (60 min) × S/25 (tarifa de la oferta) = S/25, NO S/20
        // (la tarifa general del perfil, que es lo que se habría cobrado
        // antes de este fix).
        $this->assertEquals(25.00, $lesson->price_frozen_pen);
    }

    public function test_a_class_request_without_a_linked_offer_still_uses_the_general_hourly_rate(): void
    {
        // El caso mayoritario hoy (marketplace informativo / código de
        // referido / materia abierta) no vincula ninguna oferta — debe
        // seguir funcionando exactamente igual que antes de este fix.
        $teacher = $this->userWithRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_verified' => true,
            'credits_available' => 5,
            'credits_reserved' => 0,
            'hourly_rate' => 20,
        ]);
        $subject = Subject::create(['name' => 'Física', 'level' => 'secundaria']);
        $profile->subjects()->attach($subject->id);

        [$parent, $student] = $this->parent($subject);
        $request = ClassRequest::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'class_offer_id' => null,
            'help_needed' => 'Necesita reforzar el tema.',
            'status' => 'open',
        ]);

        $this->actingAs($teacher)->post(route('lessons.store'), [
            'class_request_id' => $request->id,
            'start_time' => now()->addDay()->toDateTimeString(),
            'duration_minutes' => 60,
        ])->assertRedirect(route('teacher.lessons'));

        $this->assertEquals(20.00, Lesson::firstOrFail()->price_frozen_pen);
    }

    public function test_a_linked_offer_with_no_specific_rate_set_falls_back_to_the_general_hourly_rate(): void
    {
        $teacher = $this->userWithRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_verified' => true,
            'credits_available' => 5,
            'credits_reserved' => 0,
            'hourly_rate' => 20,
        ]);
        $subject = Subject::create(['name' => 'Química', 'level' => 'secundaria']);
        $profile->subjects()->attach($subject->id);

        $offer = ClassOffer::create([
            'teacher_profile_id' => $profile->id,
            'subject_id' => $subject->id,
            'title' => 'Química general',
            'specific_rate' => null,
            'is_active' => true,
        ]);

        [$parent, $student] = $this->parent($subject);
        $request = ClassRequest::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'class_offer_id' => $offer->id,
            'help_needed' => 'Necesita reforzar el tema.',
            'status' => 'open',
        ]);

        $this->actingAs($teacher)->post(route('lessons.store'), [
            'class_request_id' => $request->id,
            'start_time' => now()->addDay()->toDateTimeString(),
            'duration_minutes' => 60,
        ])->assertRedirect(route('teacher.lessons'));

        $this->assertEquals(20.00, Lesson::firstOrFail()->price_frozen_pen);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['password' => 'password']);
        $user->assignRole($role);

        return $user;
    }

    private function parent(Subject $subject): array
    {
        $parent = $this->userWithRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Alumno',
            'last_name' => 'Prueba',
            'grade_level' => 'secundaria',
        ]);

        return [$parent, $student];
    }
}
