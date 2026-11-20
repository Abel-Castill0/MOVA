<?php

namespace Tests\Feature;

use App\Models\ClassEvent;
use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Notifications\ClassRequestExpiredNotification;
use App\Notifications\CounterofferLapsedNotification;
use App\Notifications\UnansweredRequestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Vencimiento por OPORTUNIDAD REAL (ExpireClassRequests + ClassRequest::responseDeadline):
 * una clase pedida el lunes para el viernes por la tarde sigue abierta hasta el viernes a las 18:00 de Lima.
 * Lunes 16-nov-2026 es el día de referencia; todas las horas son de Lima salvo que se diga lo contrario.
 */
class ClassRequestOpportunityExpiryTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $limaLocal): void
    {
        Carbon::setTestNow(Carbon::parse($limaLocal, 'America/Lima')->utc());
    }

    private function sweep(): void
    {
        $this->artisan('mova:expire-class-requests')->assertExitCode(0);
    }

    private function parentWithStudent(): array
    {
        $parent = User::factory()->create(['email_verified_at' => now()]);
        $parent->assignRole('parent');
        $student = Student::create(['parent_user_id' => $parent->id, 'first_name' => 'Ana', 'last_name' => 'Pérez', 'grade_level' => 'secundaria']);

        return [$parent, $student];
    }

    private function teacher(): array
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole('teacher');
        $profile = TeacherProfile::create(['user_id' => $user->id, 'hourly_rate' => 20, 'bio' => 'Profesor.', 'is_verified' => true, 'credits_available' => 10, 'credits_reserved' => 0]);
        $profile->subjects()->sync([$this->subject->id => ['specific_rate' => null]]);

        return [$user, $profile];
    }

    /** Solicitud creada «ahora» (el reloj de prueba) con los atributos dados. */
    private function request(Student $student, array $attrs = []): ClassRequest
    {
        $request = ClassRequest::create(array_merge([
            'student_id' => $student->id, 'subject_id' => $this->subject->id, 'help_needed' => 'Necesita apoyo con álgebra.', 'status' => 'open',
        ], $attrs));
        DB::table('class_requests')->where('id', $request->id)->update(['created_at' => now(), 'updated_at' => now()]);

        return $request->fresh();
    }

    private function dated(Student $student, string $slot = 'afternoon', string $date = '2026-11-20', array $attrs = []): ClassRequest
    {
        return $this->request($student, ['preferred_date' => $date, 'preferred_slot' => $slot] + $attrs);
    }

    private function counteroffer(ClassRequest $request, TeacherProfile $profile, string $limaLocal): ClassRequest
    {
        $request->forceFill([
            'status' => 'counteroffered', 'counteroffer_time' => Carbon::parse($limaLocal, 'America/Lima')->utc(),
            'counteroffer_duration_minutes' => 60, 'counteroffer_teacher_profile_id' => $profile->id,
        ])->save();
        ClassEvent::log('counteroffer_proposed', $profile->user_id, null, $request->id);

        return $request->fresh();
    }

    // 1 ────────────────────────────────────────────────────────────────────
    public function test_a_request_made_on_monday_for_friday_stays_open_tuesday_wednesday_and_thursday(): void
    {
        $this->at('2026-11-16 10:00');
        [, $student] = $this->parentWithStudent();
        $request = $this->dated($student);

        foreach (['2026-11-17 10:00', '2026-11-18 23:00', '2026-11-19 23:59', '2026-11-20 17:59'] as $moment) {
            $this->at($moment);
            $this->sweep();
            $this->assertSame('open', $request->fresh()->status, "Sigue abierta el {$moment}.");
        }
        Notification::assertNothingSent();
        $this->assertSame('2026-11-20T23:00:00+00:00', $request->fresh()->responseDeadline()->toIso8601String(), 'Vence el viernes 18:00 Lima.');
    }

    // 2, 3, 4 ──────────────────────────────────────────────────────────────
    /** @dataProvider slotEnds */
    public function test_each_slot_expires_exactly_when_its_window_ends(string $slot, string $lastOpen, string $end): void
    {
        $this->at('2026-11-16 10:00');
        [$parent, $student] = $this->parentWithStudent();
        $request = $this->dated($student, $slot);

        $this->at($lastOpen);
        $this->sweep();
        $this->assertSame('open', $request->fresh()->status);

        $this->at($end);
        $this->sweep();
        $this->assertSame('expired', $request->fresh()->status);
        Notification::assertSentTo($parent, ClassRequestExpiredNotification::class, fn ($n) => $n->reason === 'slot_passed'
            && str_contains($n->toArray($parent)['message'], 'viernes 20 de noviembre'));
    }

    public static function slotEnds(): array
    {
        return [
            'mañana → 12:00' => ['morning', '2026-11-20 11:59', '2026-11-20 12:00'],
            'tarde → 18:00' => ['afternoon', '2026-11-20 17:59', '2026-11-20 18:00'],
            'noche → 22:00' => ['evening', '2026-11-20 21:59', '2026-11-20 22:00'],
        ];
    }

    // 5 ────────────────────────────────────────────────────────────────────
    public function test_a_request_for_today_respects_its_window_and_rejects_late_proposals(): void
    {
        $this->at('2026-11-20 13:00');
        [, $student] = $this->parentWithStudent();
        [$teacherUser] = $this->teacher();
        $request = $this->dated($student, 'afternoon');

        $this->at('2026-11-20 17:00');
        $this->sweep();
        $this->assertSame('open', $request->fresh()->status);

        // Pasada la franja, aunque el barrido aún no corrió, ya no se aceptan propuestas.
        $this->at('2026-11-20 18:01');
        $this->actingAs($teacherUser)->post(route('teacher.requests.counteroffer', $request), [
            'counteroffer_time' => Carbon::parse('2026-11-21 16:00', 'America/Lima')->utc()->toIso8601String(), 'duration_minutes' => 60,
        ])->assertSessionHasErrors('counteroffer_time');
        $this->assertSame('open', $request->fresh()->status);
        $this->assertCount(0, $this->actingAs($teacherUser)->get(route('teacher.requests'))->inertiaPage()['props']['requests'], 'La bandeja ya no la ofrece.');

        $this->sweep();
        $this->assertSame('expired', $request->fresh()->status);
    }

    // 6 ────────────────────────────────────────────────────────────────────
    public function test_a_pending_proposal_is_not_expired_by_the_unanswered_rule(): void
    {
        $this->at('2026-11-16 10:00');
        [, $student] = $this->parentWithStudent();
        [, $profile] = $this->teacher();
        $request = $this->counteroffer($this->dated($student), $profile, '2026-11-20 16:00');

        foreach (['2026-11-17 11:00', '2026-11-19 12:00', '2026-11-20 15:00'] as $moment) {
            $this->at($moment);
            $this->sweep();
            $this->assertSame('counteroffered', $request->fresh()->status, "La propuesta sigue vigente el {$moment}.");
        }
        $this->assertNotNull($request->fresh()->counteroffer_time);
        Notification::assertNothingSent();
    }

    // 7 ────────────────────────────────────────────────────────────────────
    public function test_a_confirmed_request_and_its_class_are_never_touched(): void
    {
        $this->at('2026-11-16 10:00');
        [, $student] = $this->parentWithStudent();
        [, $profile] = $this->teacher();
        $request = $this->dated($student, 'afternoon', '2026-11-20', ['status' => 'accepted']);
        $lesson = Lesson::create(['class_request_id' => $request->id, 'teacher_profile_id' => $profile->id, 'student_id' => $student->id,
            'subject_id' => $this->subject->id, 'start_time' => Carbon::parse('2026-11-20 16:00', 'America/Lima')->utc(), 'duration_minutes' => 60, 'status' => 'scheduled']);

        $this->at('2026-11-25 10:00');
        $this->sweep();
        $this->assertSame('accepted', $request->fresh()->status);
        $this->assertSame('scheduled', $lesson->fresh()->status);
        Notification::assertNothingSent();
    }

    // 8 ────────────────────────────────────────────────────────────────────
    public function test_a_dated_request_waiting_for_parental_approval_ends_with_its_window_but_not_before(): void
    {
        $this->at('2026-11-16 10:00');
        [$parent, $student] = $this->parentWithStudent();
        $dated = $this->dated($student, 'afternoon', '2026-11-20', ['status' => 'pending_parent_approval']);
        $legacy = $this->request($student, ['status' => 'pending_parent_approval', 'help_needed' => 'Otra']);

        $this->at('2026-11-19 10:00');
        $this->sweep();
        $this->assertSame('pending_parent_approval', $dated->fresh()->status);

        $this->at('2026-11-20 18:00');
        $this->actingAs($parent)->post(route('class-requests.approve', $dated))->assertStatus(422);
        $this->sweep();
        $this->assertSame('expired', $dated->fresh()->status);
        $this->assertSame('pending_parent_approval', $legacy->fresh()->status, 'Sin día concreto el control parental no se apura.');
        Notification::assertSentTo($parent, ClassRequestExpiredNotification::class, fn ($n) => $n->reason === 'approval_missing');
    }

    // 9 ────────────────────────────────────────────────────────────────────
    public function test_mentorship_stays_open_seven_days_and_the_parent_can_renew_it(): void
    {
        $this->at('2026-11-16 10:00');
        [$parent, $student] = $this->parentWithStudent();
        $this->teacher();
        $request = $this->request($student, ['is_mentorship' => true]);

        $this->at('2026-11-23 09:59');
        $this->sweep();
        $this->assertSame('open', $request->fresh()->status, '6 días y 23 h: sigue abierta.');
        $summary = $this->actingAs($parent)->get(route('class-requests.index'))->inertiaPage()['props']['requests'][0];
        $this->assertTrue($summary['can_renew'], 'Le queda menos de 2 días: se puede renovar.');

        $this->at('2026-11-23 10:00');
        $this->sweep();
        $this->assertSame('expired', $request->fresh()->status);
        Notification::assertSentTo($parent, ClassRequestExpiredNotification::class, fn ($n) => $n->reason === 'no_answer');

        $this->at('2026-11-24 08:00');
        $this->actingAs($parent)->post(route('class-requests.renew', $request))->assertSessionHasNoErrors()->assertRedirect();
        $fresh = $request->fresh();
        $this->assertSame('open', $fresh->status);
        $this->assertSame('2026-12-01T13:00:00+00:00', $fresh->responseDeadline()->toIso8601String(), '7 días desde la renovación.');

        // Otro padre no puede renovarla.
        [$other] = $this->parentWithStudent();
        $fresh->forceFill(['status' => 'expired'])->save();
        $this->actingAs($other)->post(route('class-requests.renew', $request))->assertForbidden();
    }

    public function test_one_off_dated_requests_cannot_be_renewed(): void
    {
        $this->at('2026-11-16 10:00');
        [$parent, $student] = $this->parentWithStudent();
        $request = $this->dated($student, 'afternoon', '2026-11-20', ['status' => 'expired']);

        $this->actingAs($parent)->post(route('class-requests.renew', $request))->assertStatus(422);
        $this->assertSame('expired', $request->fresh()->status);
    }

    // 10 ───────────────────────────────────────────────────────────────────
    public function test_historical_requests_without_a_day_keep_their_original_24h_rule(): void
    {
        $this->at('2026-11-16 10:00');
        [, $student] = $this->parentWithStudent();
        $legacy = $this->request($student, ['preferred_times' => ['morning_weekday', 'flexible']]);
        $this->assertSame('legacy', $legacy->deadlinePolicy());

        $this->at('2026-11-17 09:59');
        $this->sweep();
        $this->assertSame('open', $legacy->fresh()->status);
        $this->at('2026-11-17 10:00');
        $this->sweep();
        $this->assertSame('expired', $legacy->fresh()->status);
        $this->assertSame(['morning_weekday', 'flexible'], $legacy->fresh()->preferred_times, 'Los datos históricos no se reescriben.');
    }

    // 11 ───────────────────────────────────────────────────────────────────
    public function test_accept_wins_then_the_sweep_does_nothing_and_sweep_wins_then_accept_is_refused(): void
    {
        $this->at('2026-11-16 10:00');
        [$parent, $student] = $this->parentWithStudent();
        [, $profile] = $this->teacher();

        // a) El padre acepta primero: el barrido posterior ve `accepted` y no toca nada.
        $a = $this->counteroffer($this->dated($student), $profile, '2026-11-18 16:00');
        $this->actingAs($parent)->post(route('class-requests.counteroffer.accept', $a), ['counteroffer_ref' => $a->counterofferRef(), 'confirm_outside_preference' => true])->assertSessionHasNoErrors();
        $this->at('2026-11-18 16:05');
        $this->sweep();
        $this->assertSame('accepted', $a->fresh()->status);
        $this->assertSame(1, Lesson::where('class_request_id', $a->id)->count());

        // b) La propuesta vence y el barrido la procesa primero: una aceptación tardía (pestaña vieja) no agenda nada.
        $b = $this->counteroffer($this->dated($student, 'evening', '2026-11-20', ['help_needed' => 'Otra']), $profile, '2026-11-19 19:00');
        $staleRef = $b->counterofferRef();
        $this->at('2026-11-19 19:01');
        $this->sweep();
        $this->assertSame('open', $b->fresh()->status);
        $this->actingAs($parent)->post(route('class-requests.counteroffer.accept', $b), ['counteroffer_ref' => $staleRef])->assertSessionHasErrors('counteroffer');
        $this->assertSame(0, Lesson::where('class_request_id', $b->id)->count());
    }

    // 12 ───────────────────────────────────────────────────────────────────
    public function test_two_consecutive_sweeps_change_and_notify_only_once(): void
    {
        $this->at('2026-11-16 10:00');
        [$parent, $student] = $this->parentWithStudent();
        $this->dated($student, 'morning');

        $this->at('2026-11-20 12:00');
        $this->sweep();
        $this->sweep();
        Notification::assertSentToTimes($parent, ClassRequestExpiredNotification::class, 1);
        $this->assertSame(1, ClassEvent::where('event_type', 'request_expired')->count());
    }

    // 14 ───────────────────────────────────────────────────────────────────
    public function test_reminders_follow_the_requested_day_without_duplicates_or_late_spam(): void
    {
        $this->at('2026-11-16 10:00');
        [, $student] = $this->parentWithStudent();
        [$teacher] = $this->teacher();
        $request = $this->dated($student);

        $this->at('2026-11-16 21:59');
        $this->artisan('classmate:send-reminders');
        Notification::assertNothingSentTo($teacher);

        $this->at('2026-11-16 22:00'); // 12 h después: «sigue sin propuesta»
        $this->artisan('classmate:send-reminders');
        $this->artisan('classmate:send-reminders');
        Notification::assertSentToTimes($teacher, UnansweredRequestNotification::class, 1);

        $this->at('2026-11-19 11:59');
        $this->artisan('classmate:send-reminders');
        Notification::assertSentToTimes($teacher, UnansweredRequestNotification::class, 1);

        $this->at('2026-11-19 12:00'); // 24 h antes de la franja: último aviso
        $this->artisan('classmate:send-reminders');
        $this->artisan('classmate:send-reminders');
        Notification::assertSentToTimes($teacher, UnansweredRequestNotification::class, 2);
        Notification::assertSentTo($teacher, UnansweredRequestNotification::class, fn ($n) => $n->kind === 'final'
            && str_contains($n->toWhatsApp($teacher), 'viernes 20 de noviembre, por la tarde'));

        $this->at('2026-11-20 19:00'); // ya sin oportunidad: nada más
        $this->artisan('classmate:send-reminders');
        Notification::assertSentToTimes($teacher, UnansweredRequestNotification::class, 2);
        $this->assertNotNull($request->fresh()->final_reminder_sent_at);
    }

    public function test_a_request_created_close_to_its_slot_gets_a_single_spaced_last_call_and_none_when_too_late(): void
    {
        [, $student] = $this->parentWithStudent();
        [$teacher] = $this->teacher();

        // Creada el viernes 11:00 para el viernes por la tarde: el último aviso espera 3 h desde la publicación.
        $this->at('2026-11-20 11:00');
        $this->dated($student);
        $this->at('2026-11-20 13:59');
        $this->artisan('classmate:send-reminders');
        Notification::assertNothingSentTo($teacher);
        $this->at('2026-11-20 14:00');
        $this->artisan('classmate:send-reminders');
        Notification::assertSentToTimes($teacher, UnansweredRequestNotification::class, 1);

        // Creada a las 15:00 para la tarde: a las 18:00 menos 60 min ya no hay margen: ningún aviso.
        $this->at('2026-11-20 15:00');
        $this->dated($student, 'afternoon', '2026-11-20', ['help_needed' => 'Tardía']);
        $this->at('2026-11-20 17:30');
        $this->artisan('classmate:send-reminders');
        Notification::assertSentToTimes($teacher, UnansweredRequestNotification::class, 1);
    }

    public function test_no_reminder_for_a_request_with_a_pending_proposal(): void
    {
        $this->at('2026-11-16 10:00');
        [, $student] = $this->parentWithStudent();
        [$teacher, $profile] = $this->teacher();
        $this->counteroffer($this->dated($student), $profile, '2026-11-20 16:00');

        $this->at('2026-11-19 12:00');
        $this->artisan('classmate:send-reminders');
        Notification::assertNotSentTo($teacher, UnansweredRequestNotification::class);
    }

    // 15 ───────────────────────────────────────────────────────────────────
    public function test_a_proposal_whose_time_passed_reopens_the_request_while_there_is_still_opportunity(): void
    {
        $this->at('2026-11-16 10:00');
        [$parent, $student] = $this->parentWithStudent();
        [$teacher, $profile] = $this->teacher();
        $request = $this->counteroffer($this->dated($student), $profile, '2026-11-18 16:00');

        $this->at('2026-11-18 16:00');
        $this->sweep();
        $fresh = $request->fresh();
        $this->assertSame('open', $fresh->status, 'Vuelve a recibir propuestas: aún falta para el viernes.');
        $this->assertNull($fresh->counteroffer_time);
        $this->assertNull($fresh->counteroffer_teacher_profile_id);
        $this->assertSame(0, Lesson::count(), 'Nunca se confirma una clase sola.');
        Notification::assertSentTo($parent, CounterofferLapsedNotification::class, fn ($n) => $n->stillOpen && $n->audience === 'parent');
        Notification::assertSentTo($teacher, CounterofferLapsedNotification::class, fn ($n) => $n->audience === 'teacher');
        $this->assertSame(1, ClassEvent::where('event_type', 'counteroffer_lapsed')->count());
    }

    public function test_a_lapsed_proposal_with_no_opportunity_left_ends_the_request(): void
    {
        $this->at('2026-11-16 10:00');
        [$parent, $student] = $this->parentWithStudent();
        [$teacher, $profile] = $this->teacher();
        $request = $this->counteroffer($this->dated($student), $profile, '2026-11-20 17:00');

        $this->at('2026-11-20 18:10');
        $this->sweep();
        $this->assertSame('expired', $request->fresh()->status);
        $this->assertNull($request->fresh()->counteroffer_time);
        Notification::assertSentTo($parent, ClassRequestExpiredNotification::class, fn ($n) => $n->reason === 'proposal_lapsed');
        Notification::assertSentTo($teacher, CounterofferLapsedNotification::class, fn ($n) => ! $n->stillOpen);
    }

    public function test_an_alternative_proposal_after_the_window_stays_acceptable_until_its_own_time(): void
    {
        $this->at('2026-11-16 10:00');
        [, $student] = $this->parentWithStudent();
        [, $profile] = $this->teacher();
        $request = $this->counteroffer($this->dated($student), $profile, '2026-11-21 10:00'); // sábado: alternativa

        $this->at('2026-11-20 19:00'); // la franja pedida ya pasó, pero la propuesta sigue siendo aceptable
        $this->sweep();
        $this->assertSame('counteroffered', $request->fresh()->status);
        Notification::assertNothingSent();
    }

    // Fecha límite visible ─────────────────────────────────────────────────
    public function test_the_parent_and_teacher_see_the_real_deadline_not_24_hours(): void
    {
        $this->at('2026-11-16 10:00');
        [$parent, $student] = $this->parentWithStudent();
        [$teacher, $profile] = $this->teacher();
        $this->dated($student);

        $mine = $this->actingAs($parent)->get(route('class-requests.index'))->inertiaPage()['props']['requests'][0];
        $this->assertSame('2026-11-20T23:00:00+00:00', $mine['respond_by']);
        $this->assertSame('slot', $mine['deadline_policy']);
        $this->assertFalse($mine['can_renew']);
        $theirs = $this->actingAs($teacher)->get(route('teacher.requests'))->inertiaPage()['props']['requests'][0];
        $this->assertSame('2026-11-20T23:00:00+00:00', $theirs['respond_by']);

        $request = ClassRequest::firstOrFail();
        $this->counteroffer($request, $profile, '2026-11-19 16:00');
        $mine = $this->actingAs($parent)->get(route('class-requests.index'))->inertiaPage()['props']['requests'][0];
        $this->assertNull($mine['respond_by']);
        $this->assertSame('2026-11-19T21:00:00+00:00', $mine['counteroffer_respond_by'], 'La propuesta vence en su propia hora.');
    }
}
