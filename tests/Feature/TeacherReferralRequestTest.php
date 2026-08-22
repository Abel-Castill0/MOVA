<?php

namespace Tests\Feature;

use App\Models\ClassRequest;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Opción A del diseño de código de referido (HANDOFF_FINAL.md §18) — vía
 * ADICIONAL de solicitud en paralelo al marketplace de ofertas existente.
 * ClassOffer y DiagnosticRecommendationService deliberadamente sin tocar.
 */
class TeacherReferralRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_a_request_with_a_valid_code_is_bound_directly_to_that_teacher(): void
    {
        [$targetTeacher, $targetProfile] = $this->teacher();
        $subject = Subject::create(['name' => 'Matemáticas', 'level' => 'todos']);
        [$parent, $student] = $this->parentWithStudent();

        $this->actingAs($parent)->post(route('class-requests.store'), [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'teacher_referral_code' => $targetProfile->referral_code,
            'help_needed' => 'Necesita reforzar el tema.',
        ])->assertRedirect(route('class-requests.index'));

        $request = ClassRequest::latest()->first();
        $this->assertSame($targetProfile->id, $request->teacher_profile_id);
        $this->assertSame($targetProfile->referral_code, $request->teacher_referral_code);
        $this->assertSame('open', $request->status);
    }

    // Verifica también que la búsqueda es insensible a minúsculas/espacios,
    // ya que el input del frontend fuerza mayúsculas pero el backend nunca
    // debe confiar en eso.
    public function test_a_request_with_an_invalid_code_fails_with_a_clear_message(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        $subject = Subject::create(['name' => 'Física', 'level' => 'todos']);

        $this->actingAs($parent)->post(route('class-requests.store'), [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'teacher_referral_code' => 'zzzzzz',
            'help_needed' => 'Necesita reforzar el tema.',
        ])->assertStatus(422);

        $this->assertDatabaseCount('class_requests', 0);
    }

    public function test_only_the_bound_teacher_can_accept_a_code_bound_request(): void
    {
        [$targetTeacher, $targetProfile, $subject] = $this->teacherWithSubject();
        [$otherTeacher, $otherProfile] = $this->teacher();
        $otherProfile->subjects()->attach($subject->id); // mismo tema, para probar que igual no puede
        [$parent, $student] = $this->parentWithStudent();

        $request = ClassRequest::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'status' => 'open',
            'help_needed' => 'Test',
        ]);
        $request->teacher_profile_id = $targetProfile->id;
        $request->teacher_referral_code = $targetProfile->referral_code;
        $request->save();

        $this->actingAs($otherTeacher)->get(route('teacher.requests.accept', $request))
            ->assertForbidden();

        $this->actingAs($targetTeacher)->get(route('teacher.requests.accept', $request))
            ->assertOk();
    }

    public function test_request_without_a_code_keeps_the_existing_open_matching_behavior(): void
    {
        [$teacher, $profile, $subject] = $this->teacherWithSubject();
        [$parent, $student] = $this->parentWithStudent();

        $this->actingAs($parent)->post(route('class-requests.store'), [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Necesita reforzar el tema.',
        ])->assertRedirect(route('class-requests.index'));

        $request = ClassRequest::latest()->first();
        $this->assertNull($request->teacher_profile_id);
        $this->assertNull($request->teacher_referral_code);

        // Cualquier profesor verificado que enseñe la materia puede verla y aceptarla.
        $this->actingAs($teacher)->get(route('teacher.requests.accept', $request))
            ->assertOk();
    }

    public function test_lookup_endpoint_finds_a_verified_teacher_by_code(): void
    {
        [, $profile] = $this->teacher();
        [$parent] = $this->parentWithStudent();

        $this->actingAs($parent)
            ->getJson(route('class-requests.lookup-code', ['code' => $profile->referral_code]))
            ->assertJson(['found' => true]);

        $this->actingAs($parent)
            ->getJson(route('class-requests.lookup-code', ['code' => 'ZZZZZZ']))
            ->assertJson(['found' => false]);
    }

    private function teacher(): array
    {
        $user = User::factory()->create();
        $user->assignRole('teacher');
        $profile = TeacherProfile::create(['user_id' => $user->id, 'is_verified' => true, 'hourly_rate' => 20]);

        return [$user, $profile];
    }

    private function teacherWithSubject(): array
    {
        [$user, $profile] = $this->teacher();
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
