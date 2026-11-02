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

    public function test_verified_teacher_can_reject_a_request_directed_to_them(): void
    {
        [$teacher, $profile, , $request] = $this->openRequest();
        $request->teacher_profile_id = $profile->id;
        $request->save();

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

    public function test_declining_a_public_request_does_not_hide_it_from_other_eligible_teachers(): void
    {
        [$teacherA, $profileA, $subject, $request] = $this->openRequest();
        $teacherB = User::factory()->create();
        $teacherB->assignRole('teacher');
        $profileB = TeacherProfile::create([
            'user_id' => $teacherB->id,
            'is_verified' => true,
            'credits_available' => 5,
            'credits_reserved' => 0,
        ]);
        $profileB->subjects()->attach($subject->id);

        $this->actingAs($teacherA)->post(route('teacher.requests.reject', $request), [
            'reason' => 'No puedo atender el horario solicitado.',
        ])->assertRedirect();
        $this->actingAs($teacherA)->post(route('teacher.requests.reject', $request), [
            'reason' => 'No puedo atender el horario solicitado.',
        ])->assertRedirect();
        $this->assertSame(1, \App\Models\ClassRequestTeacherDecline::where('class_request_id', $request->id)->count());
        $this->assertSame(1, \App\Models\ClassEvent::where('class_request_id', $request->id)
            ->where('event_type', 'request_declined_by_teacher')->count());

        $this->assertSame('open', $request->fresh()->status);
        $this->assertDatabaseHas('class_request_teacher_declines', [
            'class_request_id' => $request->id,
            'teacher_profile_id' => $profileA->id,
            'reason' => 'No puedo atender el horario solicitado.',
        ]);

        $teacherAProps = $this->actingAs($teacherA)->get(route('teacher.requests'))
            ->assertOk()->inertiaPage()['props'];
        $this->assertCount(0, $teacherAProps['requests']);
        $this->assertCount(1, $teacherAProps['rejectedRequests']);
        $this->assertSame('No puedo atender el horario solicitado.', $teacherAProps['rejectedRequests'][0]['teacher_rejection_reason']);

        $teacherBProps = $this->actingAs($teacherB)->get(route('teacher.requests'))
            ->assertOk()->inertiaPage()['props'];
        $this->assertCount(1, $teacherBProps['requests']);
        $this->assertCount(0, $teacherBProps['rejectedRequests']);

        $this->assertFalse($teacherA->can('accept', $request->fresh()));
        $this->assertTrue($teacherB->can('accept', $request->fresh()));
        $this->assertSame($profileB->id, $teacherB->teacherProfile->id);
        $this->assertEqualsCanonicalizing([$teacherB->id], $request->fresh()->eligibleTeacherUsers()->pluck('id')->all(),
            'Un profesor que descartó la solicitud no debe recibir recordatorios posteriores.');
    }

    public function test_public_decline_does_not_send_a_final_rejection_notification_to_parent(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        [$teacher, , , $request] = $this->openRequest();

        $this->actingAs($teacher)->post(route('teacher.requests.reject', $request), [
            'reason' => 'Por ahora no tengo disponibilidad suficiente.',
        ])->assertRedirect();

        \Illuminate\Support\Facades\Notification::assertNothingSent();
        $this->assertSame('open', $request->fresh()->status);
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
