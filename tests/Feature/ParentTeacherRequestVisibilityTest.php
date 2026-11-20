<?php

namespace Tests\Feature;

use App\Models\ClassRequest;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Notifications\NewClassRequestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regresión padre -> profesor: no basta con que el POST persista una fila.
 * El listado y el contador deben ver exactamente la misma solicitud,
 * sujetos al estado, materia, referido y verificación del docente.
 */
class ParentTeacherRequestVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['parent', 'teacher'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_generic_parent_submission_appears_in_matching_verified_teacher_list_and_dashboard(): void
    {
        Notification::fake();
        [$parent, $student] = $this->parentWithStudent();
        $subject = Subject::create(['name' => 'Matemática de prueba', 'level' => 'todos']);
        [$teacher] = $this->teacherForSubject($subject);

        $this->actingAs($parent)->post(route('class-requests.store'), [...\Tests\Support\Day::tomorrow(),
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Necesito apoyo con fracciones.',
        ])->assertRedirect(route('class-requests.index'));

        $request = ClassRequest::sole();
        $this->assertSame('open', $request->status);

        $list = $this->actingAs($teacher)->get(route('teacher.requests'))
            ->assertOk()->inertiaPage()['props'];

        $this->assertCount(1, $list['requests']);
        $this->assertSame($request->id, $list['requests'][0]['id']);
        $this->assertSame('Matemática de prueba', $list['requests'][0]['subject']['name']);
        $this->assertArrayNotHasKey('parent_user_id', $list['requests'][0]['student']);
        $this->assertArrayNotHasKey('last_name', $list['requests'][0]['student']);

        $dashboard = $this->actingAs($teacher)->get(route('dashboard'))
            ->assertOk()->inertiaPage()['props'];
        $this->assertSame(1, $dashboard['pending_requests']);

        Notification::assertSentToTimes($teacher, NewClassRequestNotification::class, 1);
    }

    public function test_pending_parent_approval_is_not_visible_until_parent_approves(): void
    {
        Notification::fake();
        [$parent, $student] = $this->parentWithStudent(true);
        $subject = Subject::create(['name' => 'Física de prueba', 'level' => 'todos']);
        [$teacher] = $this->teacherForSubject($subject);

        $this->actingAs($parent)->post(route('class-requests.store'), [...\Tests\Support\Day::tomorrow(),
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Necesita ayuda con movimientos.',
        ])->assertRedirect(route('class-requests.index'));

        $request = ClassRequest::sole();
        $this->assertSame('pending_parent_approval', $request->status);
        $this->assertCount(0, $this->actingAs($teacher)->get(route('teacher.requests'))->inertiaPage()['props']['requests']);
        $this->assertSame(0, $this->actingAs($teacher)->get(route('dashboard'))->inertiaPage()['props']['pending_requests']);

        $this->actingAs($parent)->post(route('class-requests.approve', $request))->assertRedirect();

        $list = $this->actingAs($teacher)->get(route('teacher.requests'))->assertOk()->inertiaPage()['props'];
        $this->assertSame($request->id, $list['requests'][0]['id']);
        $this->assertSame(1, $this->actingAs($teacher)->get(route('dashboard'))->inertiaPage()['props']['pending_requests']);
        Notification::assertSentToTimes($teacher, NewClassRequestNotification::class, 1);
    }

    public function test_request_by_referral_code_is_visible_only_to_target_teacher(): void
    {
        Notification::fake();
        [$parent, $student] = $this->parentWithStudent();
        $subject = Subject::create(['name' => 'Inglés de prueba', 'level' => 'todos']);
        [$target, $targetProfile] = $this->teacherForSubject();
        [$other] = $this->teacherForSubject($subject);

        $this->actingAs($parent)->post(route('class-requests.store'), [...\Tests\Support\Day::tomorrow(),
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'teacher_referral_code' => $targetProfile->referral_code,
            'help_needed' => 'Quiero ayuda de este profesor.',
        ])->assertRedirect(route('class-requests.index'));

        $this->assertCount(1, $this->actingAs($target)->get(route('teacher.requests'))->inertiaPage()['props']['requests']);
        $this->assertCount(0, $this->actingAs($other)->get(route('teacher.requests'))->inertiaPage()['props']['requests']);
        Notification::assertSentToTimes($target, NewClassRequestNotification::class, 1);
        Notification::assertNothingSentTo($other);
    }

    public function test_repeated_submission_does_not_re_notify_or_create_a_second_request(): void
    {
        Notification::fake();
        [$parent, $student] = $this->parentWithStudent();
        $subject = Subject::create(['name' => 'Química de prueba', 'level' => 'todos']);
        [$teacher] = $this->teacherForSubject($subject);
        $payload = [...\Tests\Support\Day::tomorrow(),
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Preparar un examen de química.',
        ];

        $this->actingAs($parent)->post(route('class-requests.store'), $payload)->assertRedirect();
        $this->actingAs($parent)->post(route('class-requests.store'), $payload)->assertRedirect();

        $this->assertDatabaseCount('class_requests', 1);
        Notification::assertSentToTimes($teacher, NewClassRequestNotification::class, 1);
    }

    private function parentWithStudent(bool $parentalControl = false): array
    {
        $parent = User::factory()->create(['parental_control' => $parentalControl]);
        $parent->assignRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Alumno',
            'last_name' => 'Sintético',
            'grade_level' => 'secundaria',
        ]);

        return [$parent, $student];
    }

    private function teacherForSubject(?Subject $subject = null): array
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_verified' => true,
            'hourly_rate' => 20,
        ]);
        if ($subject) {
            $profile->subjects()->attach($subject->id);
        }

        return [$teacher, $profile];
    }
}
