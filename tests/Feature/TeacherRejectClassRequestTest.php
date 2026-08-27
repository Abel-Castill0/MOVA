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
 * P0 — cierra el hueco de cobertura real que dejó pasar un bug de paridad
 * SQLite/MySQL: 2026_07_08_000001_update_class_requests_status_enum.php
 * amplió el ENUM de `class_requests.status` a incluir 'teacher_rejected'
 * SOLO en la rama MySQL — en SQLite (la base con la que corre toda la
 * suite) el CHECK constraint se quedó en la lista vieja, así que la
 * MISMA escritura que ClassRequestController::teacherReject() hace en
 * producción fallaba con "CHECK constraint failed: status" si se
 * ejecutaba contra la base de test.
 *
 * TeacherVerificationGateTest ya cubre el camino BLOQUEADO (profesor sin
 * verificar → 403, la policy corta antes de llegar al `update()` — nunca
 * ejercita la escritura real). Este archivo es el camino que faltaba: un
 * profesor verificado que SÍ puede rechazar, y la escritura de
 * 'teacher_rejected' realmente ocurre contra la base de datos.
 *
 * 2026_08_27_000001_widen_class_requests_status_enum_for_sqlite.php
 * corrige el CHECK; este test es la razón de ser de esa migración —
 * sin ella, `test_verified_teacher_can_reject_an_open_class_request`
 * falla con la misma excepción que se reprodujo manualmente al
 * diagnosticar el bug.
 */
class TeacherRejectClassRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_verified_teacher_can_reject_an_open_class_request(): void
    {
        [$teacher, , , $request] = $this->openRequest();

        $this->actingAs($teacher)
            ->post(route('teacher.requests.reject', $request), [
                'reason' => 'No tengo disponibilidad esta semana.',
            ])
            ->assertRedirect();

        $request->refresh();
        $this->assertSame('teacher_rejected', $request->status);
        $this->assertNotNull($request->teacher_rejected_at);
        $this->assertSame('No tengo disponibilidad esta semana.', $request->teacher_rejection_reason);
    }

    public function test_rejecting_an_already_taken_request_is_refused(): void
    {
        [$teacher, , , $request] = $this->openRequest();
        $request->update(['status' => 'accepted']);

        $this->actingAs($teacher)
            ->post(route('teacher.requests.reject', $request), [
                'reason' => 'No tengo disponibilidad esta semana.',
            ])
            ->assertStatus(422);

        $this->assertSame('accepted', $request->fresh()->status);
    }

    public function test_reject_reason_is_required_and_has_a_minimum_length(): void
    {
        [$teacher, , , $request] = $this->openRequest();

        $this->actingAs($teacher)
            ->post(route('teacher.requests.reject', $request), ['reason' => 'corto'])
            ->assertSessionHasErrors('reason');

        $this->assertSame('open', $request->fresh()->status);
    }

    private function openRequest(): array
    {
        $teacherUser = User::factory()->create(['password' => 'password']);
        $teacherUser->assignRole('teacher');
        $subject = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'secundaria']);
        $profile = TeacherProfile::create([
            'user_id' => $teacherUser->id,
            'is_verified' => true,
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
