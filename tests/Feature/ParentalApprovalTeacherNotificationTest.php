<?php

namespace Tests\Feature;

use App\Models\ClassOffer;
use App\Models\ClassRequest;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Notifications\NewClassRequestNotification;
use App\Notifications\ParentApprovalRequestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Bug real cerrado aquí: con control parental activo, `ClassRequestCreated`
 * solo avisaba al padre (`pending_parent_approval`) y hacía `return` — los
 * profesores elegibles nunca recibían aviso, ni en ese momento (correcto,
 * la solicitud aún no está disponible) ni más tarde cuando el padre
 * aprobaba (INCORRECTO — `ClassRequestController::approve()` solo hacía
 * `update(['status' => 'open'])`, sin disparar ningún aviso).
 *
 * Fix: `ClassRequestController::approve()` ahora reutiliza
 * `ClassRequestNotifier::notifyEligibleTeachers()` — el mismo método que ya
 * usa `SendClassRequestNotifications` para el camino de creación directa
 * `open` — tras confirmar la transacción (nunca antes: el estado podría
 * revertirse por rollback).
 */
class ParentalApprovalTeacherNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    // A) Solicitud creada directamente `open` (sin control parental) -> los
    // profesores elegibles de la materia reciben exactamente 1 aviso.
    public function test_a_request_created_directly_open_notifies_eligible_teachers_once(): void
    {
        Notification::fake();

        [$parent, $student] = $this->parentWithStudent(parentalControl: false);
        [$teacherUser, , $subject] = $this->verifiedTeacherWithSubject();

        $this->actingAs($parent)->post(route('class-requests.store'), [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Necesita ayuda.',
        ])->assertRedirect();

        Notification::assertSentToTimes($teacherUser, NewClassRequestNotification::class, 1);
    }

    // B) Solicitud creada con control parental activo -> el padre recibe el
    // aviso de aprobación; los profesores TODAVÍA no reciben nada.
    public function test_a_request_pending_parent_approval_notifies_only_the_parent(): void
    {
        Notification::fake();

        [$parent, $student] = $this->parentWithStudent(parentalControl: true);
        [$teacherUser, , $subject] = $this->verifiedTeacherWithSubject();

        $this->actingAs($parent)->post(route('class-requests.store'), [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Necesita ayuda.',
        ])->assertRedirect();

        Notification::assertSentTo($parent, ParentApprovalRequestNotification::class);
        Notification::assertNothingSentTo($teacherUser);

        $this->assertSame('pending_parent_approval', ClassRequest::sole()->status);
    }

    // C) El padre aprueba -> status pasa a `open` -> los profesores elegibles
    // reciben exactamente 1 aviso (el bug real: antes, 0).
    public function test_parent_approval_notifies_eligible_teachers_exactly_once(): void
    {
        [$parent, $student] = $this->parentWithStudent(parentalControl: true);
        [$teacherUser, , $subject] = $this->verifiedTeacherWithSubject();

        $this->actingAs($parent)->post(route('class-requests.store'), [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Necesita ayuda.',
        ])->assertRedirect();

        $classRequest = ClassRequest::sole();
        $this->assertSame('pending_parent_approval', $classRequest->status);

        Notification::fake();

        $this->actingAs($parent)
            ->post(route('class-requests.approve', $classRequest))
            ->assertRedirect();

        $this->assertSame('open', $classRequest->fresh()->status);
        Notification::assertSentToTimes($teacherUser, NewClassRequestNotification::class, 1);
    }

    // D) Segunda aprobación (o cualquier intento sobre una solicitud que ya
    // no está `pending_parent_approval`) falla por estado -> 0 avisos extra.
    public function test_a_second_approval_attempt_fails_and_sends_no_extra_notifications(): void
    {
        [$parent, $student] = $this->parentWithStudent(parentalControl: true);
        [$teacherUser, , $subject] = $this->verifiedTeacherWithSubject();

        $this->actingAs($parent)->post(route('class-requests.store'), [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Necesita ayuda.',
        ])->assertRedirect();

        $classRequest = ClassRequest::sole();
        $this->actingAs($parent)->post(route('class-requests.approve', $classRequest))->assertRedirect();

        Notification::fake();

        $this->actingAs($parent)
            ->post(route('class-requests.approve', $classRequest))
            ->assertStatus(422);

        Notification::assertNothingSentTo($teacherUser);
    }

    // E) Solicitud por código de referido -> con control parental, tras la
    // aprobación solo el profesor referido recibe aviso, ningún otro
    // profesor verificado de la misma materia.
    public function test_referral_request_notifies_only_the_referred_teacher_after_approval(): void
    {
        [$parent, $student] = $this->parentWithStudent(parentalControl: true);
        $subject = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'todos']);
        $referredTeacher = $this->verifiedTeacherWithSubject($subject)[0];
        $referredProfile = TeacherProfile::where('user_id', $referredTeacher->id)->sole();
        $otherTeacher = $this->verifiedTeacherWithSubject($subject)[0];

        $this->actingAs($parent)->post(route('class-requests.store'), [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Necesita ayuda.',
            'teacher_referral_code' => $referredProfile->referral_code,
        ])->assertRedirect();

        $classRequest = ClassRequest::sole();

        Notification::fake();
        $this->actingAs($parent)->post(route('class-requests.approve', $classRequest))->assertRedirect();

        Notification::assertSentToTimes($referredTeacher, NewClassRequestNotification::class, 1);
        Notification::assertNothingSentTo($otherTeacher);
    }

    // F) Solicitud vinculada a una oferta -> tras la aprobación, solo el
    // profesor dueño de la oferta recibe aviso.
    public function test_offer_bound_request_notifies_only_the_offer_owner_after_approval(): void
    {
        [$parent, $student] = $this->parentWithStudent(parentalControl: true);
        [$offerTeacher, $offerProfile, $subject] = $this->verifiedTeacherWithSubject();
        $offer = ClassOffer::create([
            'teacher_profile_id' => $offerProfile->id,
            'subject_id' => $subject->id,
            'title' => 'Oferta activa',
            'is_active' => true,
        ]);
        $otherTeacher = $this->verifiedTeacherWithSubject($subject)[0];

        $this->actingAs($parent)->post(route('class-requests.store'), [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'class_offer_id' => $offer->id,
            'help_needed' => 'Necesita ayuda.',
        ])->assertRedirect();

        $classRequest = ClassRequest::sole();

        Notification::fake();
        $this->actingAs($parent)->post(route('class-requests.approve', $classRequest))->assertRedirect();

        Notification::assertSentToTimes($offerTeacher, NewClassRequestNotification::class, 1);
        Notification::assertNothingSentTo($otherTeacher);
    }

    // G) Solicitud genérica (sin oferta, sin código) -> tras la aprobación,
    // todos los profesores verificados de la materia reciben aviso.
    public function test_generic_request_notifies_all_eligible_teachers_of_the_subject_after_approval(): void
    {
        [$parent, $student] = $this->parentWithStudent(parentalControl: true);
        $subject = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'todos']);
        $teacherA = $this->verifiedTeacherWithSubject($subject)[0];
        $teacherB = $this->verifiedTeacherWithSubject($subject)[0];

        $this->actingAs($parent)->post(route('class-requests.store'), [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Necesita ayuda.',
        ])->assertRedirect();

        $classRequest = ClassRequest::sole();

        Notification::fake();
        $this->actingAs($parent)->post(route('class-requests.approve', $classRequest))->assertRedirect();

        Notification::assertSentToTimes($teacherA, NewClassRequestNotification::class, 1);
        Notification::assertSentToTimes($teacherB, NewClassRequestNotification::class, 1);
    }

    // H) Profesor no verificado de la misma materia -> nunca recibe aviso,
    // ni en la creación directa ni tras la aprobación del padre.
    public function test_an_unverified_teacher_is_never_notified(): void
    {
        [$parent, $student] = $this->parentWithStudent(parentalControl: true);
        $subject = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'todos']);
        $verifiedTeacher = $this->verifiedTeacherWithSubject($subject)[0];

        $unverifiedUser = User::factory()->create();
        $unverifiedUser->assignRole('teacher');
        $unverifiedProfile = TeacherProfile::create(['user_id' => $unverifiedUser->id, 'is_verified' => false]);
        $unverifiedProfile->subjects()->attach($subject->id);

        $this->actingAs($parent)->post(route('class-requests.store'), [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Necesita ayuda.',
        ])->assertRedirect();

        $classRequest = ClassRequest::sole();

        Notification::fake();
        $this->actingAs($parent)->post(route('class-requests.approve', $classRequest))->assertRedirect();

        Notification::assertSentToTimes($verifiedTeacher, NewClassRequestNotification::class, 1);
        Notification::assertNothingSentTo($unverifiedUser);
    }

    private function verifiedTeacherWithSubject(?Subject $subject = null): array
    {
        $user = User::factory()->create();
        $user->assignRole('teacher');
        $profile = TeacherProfile::create(['user_id' => $user->id, 'is_verified' => true, 'hourly_rate' => 20]);
        $subject ??= Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'todos']);
        $profile->subjects()->attach($subject->id);

        return [$user, $profile, $subject];
    }

    private function parentWithStudent(bool $parentalControl): array
    {
        $parent = User::factory()->create(['parental_control' => $parentalControl]);
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
