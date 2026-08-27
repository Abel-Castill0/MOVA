<?php

namespace Tests\Feature;

use App\Models\ClassOffer;
use App\Models\ClassRequest;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cierra los dos hallazgos P2 registrados en docs/MOVA_DESIGN_AUDIT_FINAL.md
 * durante la auditoría de contrato de negocio de ClassRequests/Create.vue —
 * reclasificados de P3 a P2 tras revisión: "botón deshabilitado" es
 * deduplicación de UI, no idempotencia de request, y "la aceptación bloquea
 * después" no es lo mismo que "no crear un estado inválido desde el
 * principio".
 *
 * 1. Validación temprana de oferta: antes solo se comprobaba
 *    is_active/is_verified en el camino de mentoría — una solicitud normal
 *    podía quedar vinculada a una oferta inactiva o de un profesor no
 *    verificado y nacer sin que nadie autorizado pudiera aceptarla nunca
 *    (solicitud fantasma).
 * 2. Deduplicación de reintento: un timeout de red que reenvía exactamente
 *    la misma intención (mismo alumno, materia, texto, profesor) dentro de
 *    una ventana corta debe colapsar en una sola ClassRequest, no crear una
 *    segunda — el botón deshabilitado en Create.vue cubre el doble-click,
 *    no un reintento de red genuino.
 */
class ClassRequestStoreIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_a_request_cannot_be_bound_to_an_inactive_offer(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        [, $profile, $subject] = $this->verifiedTeacherWithSubject();
        $offer = ClassOffer::create([
            'teacher_profile_id' => $profile->id,
            'subject_id' => $subject->id,
            'title' => 'Oferta inactiva',
            'is_active' => false,
        ]);

        $this->actingAs($parent)->post(route('class-requests.store'), [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'class_offer_id' => $offer->id,
            'help_needed' => 'Necesita ayuda.',
        ])->assertStatus(422);

        $this->assertSame(0, ClassRequest::count(), 'No debe crearse ninguna solicitud contra una oferta inactiva.');
    }

    public function test_a_request_cannot_be_bound_to_an_offer_from_an_unverified_teacher(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('teacher');
        $profile = TeacherProfile::create(['user_id' => $teacherUser->id, 'is_verified' => false]);
        $subject = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'todos']);
        $offer = ClassOffer::create([
            'teacher_profile_id' => $profile->id,
            'subject_id' => $subject->id,
            'title' => 'Oferta de profesor no verificado',
            'is_active' => true,
        ]);

        $this->actingAs($parent)->post(route('class-requests.store'), [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'class_offer_id' => $offer->id,
            'help_needed' => 'Necesita ayuda.',
        ])->assertStatus(422);

        $this->assertSame(0, ClassRequest::count());
    }

    public function test_a_request_bound_to_a_valid_active_offer_still_succeeds(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        [, $profile, $subject] = $this->verifiedTeacherWithSubject();
        $offer = ClassOffer::create([
            'teacher_profile_id' => $profile->id,
            'subject_id' => $subject->id,
            'title' => 'Oferta activa',
            'is_active' => true,
        ]);

        $this->actingAs($parent)->post(route('class-requests.store'), [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'class_offer_id' => $offer->id,
            'help_needed' => 'Necesita ayuda.',
        ])->assertRedirect(route('class-requests.index'));

        $this->assertSame(1, ClassRequest::count());
        $this->assertSame($offer->id, ClassRequest::first()->class_offer_id);
    }

    public function test_resubmitting_the_exact_same_intent_within_the_window_does_not_duplicate(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        $subject = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'todos']);

        $payload = [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Necesita reforzar el tema, es lo mismo dos veces.',
        ];

        // Simula un reintento de red genuino: el mismo POST llega dos veces.
        $this->actingAs($parent)->post(route('class-requests.store'), $payload)
            ->assertRedirect(route('class-requests.index'));
        $this->actingAs($parent)->post(route('class-requests.store'), $payload)
            ->assertRedirect(route('class-requests.index'));

        $this->assertSame(1, ClassRequest::count(), 'Dos POSTs idénticos en la ventana de dedup deben colapsar en una sola solicitud.');
    }

    public function test_a_genuinely_different_request_right_after_is_not_treated_as_a_duplicate(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        $subjectA = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'todos']);
        $subjectB = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'todos']);

        $this->actingAs($parent)->post(route('class-requests.store'), [
            'student_id' => $student->id,
            'subject_id' => $subjectA->id,
            'help_needed' => 'Necesita ayuda con A.',
        ])->assertRedirect();

        $this->actingAs($parent)->post(route('class-requests.store'), [
            'student_id' => $student->id,
            'subject_id' => $subjectB->id,
            'help_needed' => 'Necesita ayuda con B.',
        ])->assertRedirect();

        $this->assertSame(2, ClassRequest::count(), 'Dos intenciones genuinamente distintas (materia distinta) no deben deduplicarse.');
    }

    private function verifiedTeacherWithSubject(): array
    {
        $user = User::factory()->create();
        $user->assignRole('teacher');
        $profile = TeacherProfile::create(['user_id' => $user->id, 'is_verified' => true, 'hourly_rate' => 20]);
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
}
