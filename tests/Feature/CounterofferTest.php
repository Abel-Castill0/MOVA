<?php

namespace Tests\Feature;

use App\Models\ClassRequest;
use App\Models\CreditTransaction;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Notifications\CounterofferProposedNotification;
use App\Notifications\CounterofferRejectedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Contraoferta de horario rediseñada (integración de la rama de UI):
 *   profesor elegible propone hora exacta + duración  → counteroffered
 *   padre dueño acepta → Lesson vía LessonSchedulingService (misma primitiva
 *                        que la aceptación normal: créditos, reserva, agenda, JaaS)
 *   padre dueño rechaza → open, campos limpios, sin tocar créditos
 *
 * La versión original de la rama exponía un webhook público "SI/NO" que
 * aceptaba sin autenticación y generaba un link de meet.jit.si saltándose la
 * Lesson. Estas pruebas fijan que ese camino no existe y que la aceptación
 * respeta TODOS los invariantes financieros y de agenda.
 */
class CounterofferTest extends TestCase
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
        Notification::fake();
    }

    private function teacher(bool $verified = true, int $available = 10, ?Subject $subject = null): array
    {
        $user = User::factory()->create();
        $user->assignRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $user->id,
            'hourly_rate' => 20,
            'bio' => 'Profesor con experiencia.',
            'is_verified' => $verified,
            'credits_available' => $available,
            'credits_reserved' => 0,
        ]);
        $profile->subjects()->sync([($subject ?? $this->subject)->id => ['specific_rate' => null]]);

        return [$user, $profile];
    }

    private function parentWithStudent(): array
    {
        $parent = User::factory()->create(['phone' => '911222333']);
        $parent->assignRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'birth_date' => '2014-05-01',
            'grade_level' => 'secundaria',
            'school' => 'Colegio Secreto',
        ]);

        return [$parent, $student];
    }

    private function openRequest(Student $student): ClassRequest
    {
        return ClassRequest::create([
            'student_id' => $student->id,
            'subject_id' => $this->subject->id,
            'help_needed' => 'Ecuaciones',
            'preferred_times' => 'Tardes',
            'status' => 'open',
        ]);
    }

    private function when(int $daysAhead = 2, int $hour = 15): Carbon
    {
        return now()->utc()->addDays($daysAhead)->setTime($hour, 0);
    }

    private function propose(User $teacher, ClassRequest $request, Carbon $at, int $minutes = 90)
    {
        return $this->actingAs($teacher)->post(route('teacher.requests.counteroffer', $request), [
            'counteroffer_time' => $at->toIso8601String(),
            'duration_minutes' => $minutes,
        ]);
    }

    private function counteroffered(int $minutes = 90, int $credits = 10): array
    {
        [$teacher, $profile] = $this->teacher(true, $credits);
        [$parent, $student] = $this->parentWithStudent();
        $request = $this->openRequest($student);
        $at = $this->when();
        $this->propose($teacher, $request, $at, $minutes)->assertSessionHasNoErrors();

        return [$teacher, $profile, $parent, $student, $request->fresh(), $at];
    }

    /** Cuerpo que envía la página del padre: la huella de la propuesta que ve. */
    private function seen(ClassRequest $request): array
    {
        return ['counteroffer_ref' => $request->fresh()->counterofferRef() ?? str_repeat('0', 64)];
    }

    // ── AUTH ─────────────────────────────────────────────────────────────

    public function test_no_public_counteroffer_webhook_exists(): void
    {
        $this->assertFalse(Route::has('webhooks.counteroffer-response'));
        $this->postJson('/api/webhooks/counteroffer-response', ['class_request_id' => 1, 'response' => 'SI'])
            ->assertNotFound();
    }

    public function test_guest_cannot_propose_accept_or_reject(): void
    {
        [, $student] = $this->parentWithStudent();
        $request = $this->openRequest($student);

        $this->post(route('teacher.requests.counteroffer', $request), [])->assertRedirect(route('login'));
        $this->post(route('class-requests.counteroffer.accept', $request))->assertRedirect(route('login'));
        $this->post(route('class-requests.counteroffer.reject', $request))->assertRedirect(route('login'));
        $this->assertSame('open', $request->fresh()->status);
    }

    public function test_unrelated_teacher_cannot_counteroffer(): void
    {
        $other = Subject::firstOrCreateByName('Química');
        [$teacher] = $this->teacher(true, 10, $other);
        [, $student] = $this->parentWithStudent();
        $request = $this->openRequest($student);

        $this->propose($teacher, $request, $this->when())->assertForbidden();
        $this->assertSame('open', $request->fresh()->status);
    }

    public function test_unverified_teacher_cannot_counteroffer(): void
    {
        [$teacher] = $this->teacher(false);
        [, $student] = $this->parentWithStudent();
        $request = $this->openRequest($student);

        $this->propose($teacher, $request, $this->when())->assertForbidden();
        $this->assertSame('open', $request->fresh()->status);
    }

    public function test_suspended_teacher_is_blocked(): void
    {
        [$teacher] = $this->teacher();
        $teacher->update(['suspended_at' => now()]);
        [, $student] = $this->parentWithStudent();
        $request = $this->openRequest($student);

        $this->propose($teacher, $request, $this->when())->assertRedirect(route('suspended'));
        $this->assertSame('open', $request->fresh()->status);
    }

    public function test_unrelated_parent_cannot_accept_or_reject(): void
    {
        [, , , , $request] = $this->counteroffered();
        [$stranger] = $this->parentWithStudent();

        $this->actingAs($stranger)->post(route('class-requests.counteroffer.accept', $request), $this->seen($request))->assertForbidden();
        $this->actingAs($stranger)->post(route('class-requests.counteroffer.reject', $request), $this->seen($request))->assertForbidden();
        $this->assertSame('counteroffered', $request->fresh()->status);
        $this->assertSame(0, Lesson::count());
    }

    public function test_teacher_cannot_use_parent_accept_route(): void
    {
        [$teacher, , , , $request] = $this->counteroffered();

        $this->actingAs($teacher)->post(route('class-requests.counteroffer.accept', $request), $this->seen($request))->assertForbidden();
        $this->assertSame(0, Lesson::count());
    }

    // ── STATE ────────────────────────────────────────────────────────────

    public function test_open_to_counteroffered_stores_exact_proposal_and_notifies_parent(): void
    {
        [$teacher, $profile, $parent, , $request, $at] = $this->counteroffered(90);

        $this->assertSame('counteroffered', $request->status);
        $this->assertTrue($request->counteroffer_time->equalTo($at));
        $this->assertSame(90, $request->counteroffer_duration_minutes);
        $this->assertSame($profile->id, $request->counteroffer_teacher_profile_id);
        Notification::assertSentTo($parent, CounterofferProposedNotification::class);
    }

    public function test_cannot_counteroffer_a_request_that_is_not_open(): void
    {
        [, , , , $request] = $this->counteroffered();
        [$secondTeacher] = $this->teacher();

        $this->propose($secondTeacher, $request, $this->when(3))->assertSessionHasErrors('counteroffer_time');
        $this->assertSame('counteroffered', $request->fresh()->status);
    }

    public function test_proposal_validation_rejects_past_time_and_out_of_range_duration(): void
    {
        [$teacher] = $this->teacher();
        [, $student] = $this->parentWithStudent();
        $request = $this->openRequest($student);

        $this->propose($teacher, $request, now()->subHour())->assertSessionHasErrors('counteroffer_time');
        $this->propose($teacher, $request, $this->when(), 20)->assertSessionHasErrors('duration_minutes');
        $this->propose($teacher, $request, $this->when(), 300)->assertSessionHasErrors('duration_minutes');
        $this->assertSame('open', $request->fresh()->status);
    }

    public function test_parent_rejection_reopens_request_clears_fields_and_moves_no_credits(): void
    {
        [$teacher, $profile, $parent, , $request] = $this->counteroffered();

        $this->actingAs($parent)->post(route('class-requests.counteroffer.reject', $request), $this->seen($request))->assertSessionHasNoErrors();

        $fresh = $request->fresh();
        $this->assertSame('open', $fresh->status);
        $this->assertNull($fresh->counteroffer_time);
        $this->assertNull($fresh->counteroffer_duration_minutes);
        $this->assertNull($fresh->counteroffer_teacher_profile_id);
        $this->assertSame(10, $profile->fresh()->credits_available);
        $this->assertSame(0, CreditTransaction::count());
        Notification::assertSentTo($teacher, CounterofferRejectedNotification::class);
    }

    public function test_accepting_an_open_request_via_counteroffer_route_fails(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        $request = $this->openRequest($student);

        $this->actingAs($parent)->post(route('class-requests.counteroffer.accept', $request), $this->seen($request))->assertSessionHasErrors('counteroffer');
        $this->assertSame(0, Lesson::count());
    }

    // ── ACCEPTANCE: FINANCE, JaaS, SCHEDULE ──────────────────────────────

    public function test_parent_acceptance_creates_exactly_one_lesson_with_the_proposed_terms(): void
    {
        [, $profile, $parent, $student, $request, $at] = $this->counteroffered(90);

        $this->actingAs($parent)->post(route('class-requests.counteroffer.accept', $request), $this->seen($request))
            ->assertRedirect(route('parent.lessons'));

        $this->assertSame(1, Lesson::count());
        $lesson = Lesson::sole();
        $this->assertSame($profile->id, $lesson->teacher_profile_id);
        $this->assertSame($student->id, $lesson->student_id);
        $this->assertSame($request->id, $lesson->class_request_id);
        $this->assertTrue($lesson->start_time->equalTo($at));
        $this->assertSame(90, $lesson->duration_minutes);
        $this->assertSame('scheduled', $lesson->status);
        $this->assertSame('accepted', $request->fresh()->status);

        // 90 min = 2 créditos (ceil(90/60)); price_frozen_pen = tarifa × créditos.
        $fresh = $profile->fresh();
        $this->assertSame(8, $fresh->credits_available);
        $this->assertSame(2, $fresh->credits_reserved);
        $this->assertEquals(40.0, (float) $lesson->price_frozen_pen);

        $ledger = CreditTransaction::all();
        $this->assertCount(1, $ledger);
        $this->assertSame('reservation', $ledger[0]->type);
        $this->assertSame(2, (int) $ledger[0]->amount);
        $this->assertSame("lesson:{$lesson->id}:reservation", $ledger[0]->idempotency_key);
        $this->assertSame(0, CreditTransaction::where('type', 'consumption')->count());

        // Sala JaaS de la arquitectura actual — nunca meet.jit.si.
        $this->assertStringStartsWith("mova-lesson-{$lesson->id}-", $lesson->jitsi_room);
        $this->assertStringNotContainsString('meet.jit.si', json_encode($lesson->toArray()));
    }

    public function test_duplicate_accept_creates_one_lesson_and_one_reservation(): void
    {
        [, , $parent, , $request] = $this->counteroffered();

        $this->actingAs($parent)->post(route('class-requests.counteroffer.accept', $request), $this->seen($request))->assertRedirect(route('parent.lessons'));
        $this->actingAs($parent)->post(route('class-requests.counteroffer.accept', $request), $this->seen($request))->assertSessionHasErrors('counteroffer');

        $this->assertSame(1, Lesson::count());
        $this->assertSame(1, CreditTransaction::where('type', 'reservation')->count());
    }

    public function test_normal_teacher_accept_cannot_win_over_a_pending_counteroffer(): void
    {
        [, , , $student, $request] = $this->counteroffered();
        [$otherTeacher] = $this->teacher();

        $this->actingAs($otherTeacher)->post(route('lessons.store'), [
            'class_request_id' => $request->id,
            'start_time' => $this->when(4)->toIso8601String(),
            'duration_minutes' => 60,
        ])->assertSessionHasErrors('accept');

        $this->assertSame(0, Lesson::count());
        $this->assertSame('counteroffered', $request->fresh()->status);
    }

    public function test_insufficient_credits_at_acceptance_rolls_back_everything(): void
    {
        [, $profile, $parent, , $request] = $this->counteroffered(90, 5);
        $profile->update(['credits_available' => 1]); // gastó créditos después de proponer

        $this->actingAs($parent)->post(route('class-requests.counteroffer.accept', $request), $this->seen($request))->assertSessionHasErrors('accept');

        $this->assertSame(0, Lesson::count());
        $this->assertSame(0, CreditTransaction::count());
        $this->assertSame('counteroffered', $request->fresh()->status);
        $this->assertSame(1, $profile->fresh()->credits_available);
    }

    public function test_teacher_schedule_conflict_at_acceptance_rolls_back_everything(): void
    {
        [, $profile, $parent, , $request, $at] = $this->counteroffered(90);
        [, $otherStudent] = $this->parentWithStudent();
        Lesson::create([
            'teacher_profile_id' => $profile->id,
            'student_id' => $otherStudent->id,
            'start_time' => $at->copy()->addMinutes(30),
            'duration_minutes' => 60,
            'price_frozen_pen' => 20,
            'status' => 'scheduled',
        ]);

        $this->actingAs($parent)->post(route('class-requests.counteroffer.accept', $request), $this->seen($request))->assertSessionHasErrors('start_time');

        $this->assertSame(1, Lesson::count());
        $this->assertSame(0, CreditTransaction::count());
        $this->assertSame('counteroffered', $request->fresh()->status);
        $this->assertSame(10, $profile->fresh()->credits_available);
    }

    public function test_student_schedule_conflict_at_acceptance_rolls_back_everything(): void
    {
        [, $profile, $parent, $student, $request, $at] = $this->counteroffered(90);
        [, $otherProfile] = $this->teacher();
        Lesson::create([
            'teacher_profile_id' => $otherProfile->id,
            'student_id' => $student->id,
            'start_time' => $at,
            'duration_minutes' => 60,
            'price_frozen_pen' => 20,
            'status' => 'scheduled',
        ]);

        $this->actingAs($parent)->post(route('class-requests.counteroffer.accept', $request), $this->seen($request))->assertSessionHasErrors('start_time');

        $this->assertSame('counteroffered', $request->fresh()->status);
        $this->assertSame(10, $profile->fresh()->credits_available);
        $this->assertSame(0, CreditTransaction::count());
    }

    public function test_teacher_no_longer_verified_cannot_be_scheduled_via_acceptance(): void
    {
        [, $profile, $parent, , $request] = $this->counteroffered();
        $profile->update(['is_verified' => false]);

        $this->actingAs($parent)->post(route('class-requests.counteroffer.accept', $request), $this->seen($request))->assertSessionHasErrors('counteroffer');

        $this->assertSame(0, Lesson::count());
        $this->assertSame('counteroffered', $request->fresh()->status);
    }

    public function test_expired_proposal_cannot_be_accepted_but_can_be_rejected(): void
    {
        [, , $parent, , $request] = $this->counteroffered();
        $this->travelTo(now()->addDays(5));

        $this->actingAs($parent)->post(route('class-requests.counteroffer.accept', $request), $this->seen($request))->assertSessionHasErrors('counteroffer');
        $this->assertSame(0, Lesson::count());

        $this->actingAs($parent)->post(route('class-requests.counteroffer.reject', $request), $this->seen($request))->assertSessionHasNoErrors();
        $this->assertSame('open', $request->fresh()->status);
    }

    public function test_stale_page_cannot_accept_a_different_proposal(): void
    {
        [, , $parent, , $request] = $this->counteroffered(90);
        $stale = $this->seen($request); // pestaña vieja con la propuesta original

        $this->actingAs($parent)->post(route('class-requests.counteroffer.reject', $request), $this->seen($request))->assertSessionHasNoErrors();
        [$secondTeacher] = $this->teacher();
        $this->propose($secondTeacher, $request->fresh(), $this->when(3), 120)->assertSessionHasNoErrors();

        $this->actingAs($parent)->post(route('class-requests.counteroffer.accept', $request), $stale)->assertSessionHasErrors('counteroffer');
        $this->actingAs($parent)->post(route('class-requests.counteroffer.reject', $request), $stale)->assertSessionHasErrors('counteroffer');

        $this->assertSame(0, Lesson::count());
        $this->assertSame(0, CreditTransaction::count());
        $this->assertSame('counteroffered', $request->fresh()->status);
        $this->assertSame(120, $request->fresh()->counteroffer_duration_minutes);
    }

    public function test_stale_page_cannot_accept_an_identical_re_proposal_after_rejecting(): void
    {
        [$teacher, , $parent, , $request, $at] = $this->counteroffered(90);
        $stale = $this->seen($request);

        $this->actingAs($parent)->post(route('class-requests.counteroffer.reject', $request), $this->seen($request))->assertSessionHasNoErrors();
        // Mismo profesor, misma hora, misma duración: sigue siendo OTRA propuesta.
        $this->propose($teacher, $request->fresh(), $at, 90)->assertSessionHasNoErrors();

        $this->actingAs($parent)->post(route('class-requests.counteroffer.accept', $request), $stale)->assertSessionHasErrors('counteroffer');
        $this->assertSame(0, Lesson::count());
        $this->assertSame(0, CreditTransaction::count());

        // La página recargada (huella nueva) sí puede aceptarla.
        $this->actingAs($parent)->post(route('class-requests.counteroffer.accept', $request), $this->seen($request))->assertRedirect(route('parent.lessons'));
        $this->assertSame(1, Lesson::count());
    }

    public function test_accept_and_reject_require_the_proposal_reference(): void
    {
        [, , $parent, , $request] = $this->counteroffered();

        $this->actingAs($parent)->post(route('class-requests.counteroffer.accept', $request))->assertSessionHasErrors('counteroffer_ref');
        $this->actingAs($parent)->post(route('class-requests.counteroffer.reject', $request))->assertSessionHasErrors('counteroffer_ref');

        $this->assertSame(0, Lesson::count());
        $this->assertSame('counteroffered', $request->fresh()->status);
    }

    // ── PII ──────────────────────────────────────────────────────────────

    public function test_teacher_counteroffered_payload_is_allowlisted(): void
    {
        [$teacher] = $this->counteroffered();

        $props = $this->actingAs($teacher)->get(route('teacher.requests'))->assertOk()->inertiaPage()['props'];
        $this->assertCount(1, $props['counterofferedRequests']);
        $json = json_encode($props['counterofferedRequests']);

        foreach (['2014-05-01', 'Colegio Secreto', '911222333', 'parent_user_id', 'counteroffer_teacher_profile_id'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
        $this->assertSame(['first_name', 'last_name', 'grade_level'], array_keys($props['counterofferedRequests'][0]['student']));
        $this->assertSame(90, $props['counterofferedRequests'][0]['counteroffer_duration_minutes']);
    }

    public function test_parent_sees_only_teacher_display_name_for_the_proposal(): void
    {
        [$teacher, , $parent] = $this->counteroffered();

        $req = $this->actingAs($parent)->get(route('class-requests.index'))->assertOk()->inertiaPage()['props']['requests'][0];

        $this->assertSame(['name' => $teacher->name], $req['counteroffer_teacher']);
        $this->assertStringNotContainsString($teacher->email, json_encode($req));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $req['counteroffer_ref']);
        $this->assertArrayNotHasKey('counteroffer_teacher_profile_id', $req);
    }

    public function test_teacher_whose_verification_was_revoked_stops_seeing_the_proposal_details(): void
    {
        [$teacher, $profile] = $this->counteroffered();
        $profile->update(['is_verified' => false]);

        // Usuario fresco, como en una petición HTTP real (sin la relación
        // teacherProfile cacheada de cuando propuso).
        $response = $this->actingAs($teacher->fresh())->get(route('teacher.requests'));

        if ($response->status() === 200) {
            $this->assertSame([], $response->inertiaPage()['props']['counterofferedRequests']);
        } else {
            $this->assertContains($response->status(), [302, 403]);
        }
    }
}
