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
 * Hallazgo CRÍTICO de auditoría (2026-08-22): ClassRequestPolicy::accept()
 * nunca comprobaba is_verified. MOVA declara que todo profesor pasa por
 * verificación de admin antes de dictar su primera clase — un profesor sin
 * verificar podía aceptar una solicitud real y quedar a solas en
 * videollamada con un menor. Probado empíricamente antes del fix con una
 * transacción revertida, sin tocar datos reales.
 */
class TeacherVerificationGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_unverified_teacher_cannot_accept_an_open_class_request(): void
    {
        [$teacher, $profile, $subject, $request] = $this->openRequest(isVerified: false);

        $this->actingAs($teacher)->get(route('teacher.requests.accept', $request))
            ->assertForbidden();

        $this->actingAs($teacher)->post(route('lessons.store'), [
            'class_request_id' => $request->id,
            'start_time' => now()->addDay()->toIso8601String(),
            'duration_minutes' => 60,
        ])->assertForbidden();

        $this->assertSame('open', $request->fresh()->status);
        $this->assertDatabaseCount('classes', 0);
    }

    public function test_unverified_teacher_cannot_reject_an_open_class_request(): void
    {
        [$teacher, , , $request] = $this->openRequest(isVerified: false);

        $this->actingAs($teacher)->post(route('teacher.requests.reject', $request))
            ->assertForbidden();

        $this->assertSame('open', $request->fresh()->status);
    }

    public function test_verified_teacher_can_still_accept_a_matching_open_request(): void
    {
        // Guard de regresión inverso: el fix no debe bloquear al camino sano.
        [$teacher, $profile, , $request] = $this->openRequest(isVerified: true);

        $this->actingAs($teacher)->get(route('teacher.requests.accept', $request))
            ->assertOk();

        $this->actingAs($teacher)->post(route('lessons.store'), [
            'class_request_id' => $request->id,
            'start_time' => now()->addDay()->toIso8601String(),
            'duration_minutes' => 60,
        ])->assertRedirect();

        $this->assertSame('accepted', $request->fresh()->status);
        $this->assertDatabaseCount('classes', 1);
    }

    private function openRequest(bool $isVerified): array
    {
        $teacherUser = User::factory()->create(['password' => 'password']);
        $teacherUser->assignRole('teacher');
        $subject = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'secundaria']);
        $profile = TeacherProfile::create([
            'user_id' => $teacherUser->id,
            'is_verified' => $isVerified,
            'credits_available' => 5,
            'credits_reserved' => 0,
        ]);
        $profile->subjects()->attach($subject->id);

        $parentUser = User::factory()->create(['password' => 'password']);
        $parentUser->assignRole('parent');
        $student = Student::create([
            'parent_user_id' => $parentUser->id,
            'first_name' => 'Alumno',
            'last_name' => 'Prueba',
            'grade_level' => 'secundaria',
        ]);
        $request = ClassRequest::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Necesita reforzar el tema.',
            'status' => 'open',
        ]);

        return [$teacherUser, $profile, $subject, $request];
    }
}
