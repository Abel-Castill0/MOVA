<?php

namespace Tests\Feature;

use App\Models\ClassOffer;
use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Notifications\NewClassRequestNotification;
use App\Support\LimaClock;
use App\Support\RequestSlots;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * «¿Cuándo necesitas la clase?»: día + franja (Mañana / Tarde / Noche) en hora de Lima.
 * Es una PREFERENCIA: la clase solo existe cuando el profesor propone una hora exacta y la familia la acepta.
 */
class RequestDayAndSlotTest extends TestCase
{
    use RefreshDatabase;

    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role);
        }
        $this->subject = Subject::firstOrCreate(['name' => 'Matemática']);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Fija «ahora» a una hora de pared de Lima ('2026-11-19 15:00'). */
    private function nowLima(string $local): void
    {
        Carbon::setTestNow(Carbon::parse($local, 'America/Lima')->utc());
    }

    private function teacher(int $credits = 10): array
    {
        $user = User::factory()->create();
        $user->assignRole('teacher');
        $profile = TeacherProfile::create(['user_id' => $user->id, 'hourly_rate' => 20, 'bio' => 'Profesor.', 'is_verified' => true, 'credits_available' => $credits, 'credits_reserved' => 0]);
        $profile->subjects()->sync([$this->subject->id => ['specific_rate' => null]]);

        return [$user, $profile];
    }

    private function parentWithStudent(): array
    {
        $parent = User::factory()->create(['phone' => '911222333']);
        $parent->assignRole('parent');
        $student = Student::create(['parent_user_id' => $parent->id, 'first_name' => 'Ana', 'last_name' => 'Pérez', 'birth_date' => '2014-05-01', 'grade_level' => 'secundaria', 'school' => 'Colegio']);

        return [$parent, $student];
    }

    private function submit(User $parent, Student $student, array $extra = [])
    {
        return $this->actingAs($parent)->post(route('class-requests.store'), array_merge([
            'student_id' => $student->id,
            'subject_id' => $this->subject->id,
            'help_needed' => 'Necesita repasar fracciones.',
        ], $extra));
    }

    // ── Configuración centralizada ──────────────────────────────────────────

    public function test_slot_windows_are_centralized_and_converted_from_lima_to_utc_once(): void
    {
        $this->assertSame(['morning', 'afternoon', 'evening'], RequestSlots::keys());
        [$start, $end] = RequestSlots::window('2026-11-20', 'afternoon');
        $this->assertSame('2026-11-20T17:00:00+00:00', $start->toIso8601String(), '12:00 Lima = 17:00 UTC');
        $this->assertSame('2026-11-20T23:00:00+00:00', $end->toIso8601String(), '18:00 Lima = 23:00 UTC');
        $this->assertNull(RequestSlots::window('2026-02-30', 'afternoon'));
        $this->assertNull(RequestSlots::window('2026-11-20', 'madrugada'));
    }

    public function test_description_reads_like_the_teacher_expects(): void
    {
        $this->assertSame('viernes 20 de noviembre, por la tarde', RequestSlots::describe('2026-11-20', 'afternoon'));
        $this->assertSame('sábado 21 de noviembre, por la mañana', RequestSlots::describe('2026-11-21', 'morning'));
        $this->assertSame('domingo 22 de noviembre, por la noche', RequestSlots::describe('2026-11-22', 'evening'));
    }

    // ── Creación y validación ───────────────────────────────────────────────

    public function test_a_parent_can_request_a_day_and_slot_and_the_teacher_sees_it_with_the_exact_hour_pending(): void
    {
        $this->nowLima('2026-11-19 10:00');
        [$teacher] = $this->teacher();
        [$parent, $student] = $this->parentWithStudent();

        $this->submit($parent, $student, ['preferred_date' => '2026-11-20', 'preferred_slot' => 'afternoon'])
            ->assertSessionHasNoErrors()->assertRedirect(route('class-requests.index'));

        $request = ClassRequest::firstOrFail();
        $this->assertSame('2026-11-20', $request->preferred_date);
        $this->assertSame('afternoon', $request->preferred_slot);
        $this->assertSame('open', $request->status, 'Es una preferencia: no se crea ninguna clase.');
        $this->assertSame(0, Lesson::count());

        $row = $this->actingAs($teacher)->get(route('teacher.requests'))->assertOk()->inertiaPage()['props']['requests'][0];
        $this->assertSame('viernes 20 de noviembre, por la tarde', $row['preference_label']);
        $this->assertSame('afternoon', $row['preferred_slot']);
        $this->assertArrayNotHasKey('start_time', $row);
    }

    public function test_the_teacher_notification_carries_the_day_and_slot(): void
    {
        $this->nowLima('2026-11-19 10:00');
        [$teacher] = $this->teacher();
        [$parent, $student] = $this->parentWithStudent();
        $this->submit($parent, $student, ['preferred_date' => '2026-11-20', 'preferred_slot' => 'afternoon']);

        $request = ClassRequest::firstOrFail()->load('subject', 'student');
        $notification = new NewClassRequestNotification($request);
        $this->assertStringContainsString('viernes 20 de noviembre, por la tarde', $notification->toWhatsApp($teacher));
        $this->assertSame('viernes 20 de noviembre, por la tarde', $notification->toArray($teacher)['preference']);
    }

    public function test_day_and_slot_are_required_for_a_one_off_class(): void
    {
        [$parent, $student] = $this->parentWithStudent();
        $this->submit($parent, $student)->assertSessionHasErrors(['preferred_date', 'preferred_slot']);
        $this->submit($parent, $student, ['preferred_date' => '2026-11-20'])->assertSessionHasErrors('preferred_slot');
        $this->submit($parent, $student, ['preferred_slot' => 'morning'])->assertSessionHasErrors('preferred_date');
        $this->assertSame(0, ClassRequest::count());
    }

    public function test_mentorship_does_not_need_a_day_and_never_stores_one(): void
    {
        [$parent, $student] = $this->parentWithStudent();

        $this->submit($parent, $student, ['is_mentorship' => true, 'preferred_date' => '2026-11-20', 'preferred_slot' => 'morning'])
            ->assertSessionHasNoErrors();
        $request = ClassRequest::firstOrFail();
        $this->assertTrue($request->is_mentorship);
        $this->assertNull($request->preferred_date);
        $this->assertNull($request->preferred_slot);
    }

    public function test_past_dates_unknown_slots_and_impossible_dates_are_rejected_by_the_server(): void
    {
        $this->nowLima('2026-11-19 10:00');
        [$parent, $student] = $this->parentWithStudent();

        $this->submit($parent, $student, ['preferred_date' => '2026-11-18', 'preferred_slot' => 'evening'])->assertSessionHasErrors('preferred_date');
        $this->submit($parent, $student, ['preferred_date' => '2026-11-20', 'preferred_slot' => 'madrugada'])->assertSessionHasErrors('preferred_slot');
        $this->submit($parent, $student, ['preferred_date' => '2026-02-30', 'preferred_slot' => 'morning'])->assertSessionHasErrors('preferred_date');
        $this->submit($parent, $student, ['preferred_date' => '20/11/2026', 'preferred_slot' => 'morning'])->assertSessionHasErrors('preferred_date');
        $this->submit($parent, $student, ['preferred_date' => '2027-06-01', 'preferred_slot' => 'morning'])->assertSessionHasErrors('preferred_date');
        $this->assertSame(0, ClassRequest::count());
    }

    public function test_today_only_offers_slots_that_have_not_ended(): void
    {
        $this->nowLima('2026-11-19 15:00'); // la mañana (06–12) ya terminó; la tarde (12–18) sigue
        [$parent, $student] = $this->parentWithStudent();

        $this->submit($parent, $student, ['preferred_date' => '2026-11-19', 'preferred_slot' => 'morning'])->assertSessionHasErrors('preferred_slot');
        $this->assertSame(0, ClassRequest::count());
        $this->submit($parent, $student, ['preferred_date' => '2026-11-19', 'preferred_slot' => 'afternoon'])->assertSessionHasNoErrors();
        $this->submit($parent, $student, ['preferred_date' => '2026-11-19', 'preferred_slot' => 'evening', 'help_needed' => 'Otra necesidad distinta.'])->assertSessionHasNoErrors();
        $this->assertSame(2, ClassRequest::count());
    }

    public function test_today_is_decided_in_lima_not_utc(): void
    {
        // 03:00 UTC del 20-nov = 22:00 del 19-nov en Lima: «hoy» sigue siendo el 19 y la noche (18–22) ya terminó.
        Carbon::setTestNow(Carbon::parse('2026-11-20 03:00:00', 'UTC'));
        [$parent, $student] = $this->parentWithStudent();

        $this->submit($parent, $student, ['preferred_date' => '2026-11-19', 'preferred_slot' => 'evening'])->assertSessionHasErrors('preferred_slot');
        $this->submit($parent, $student, ['preferred_date' => '2026-11-19', 'preferred_slot' => 'afternoon'])->assertSessionHasErrors('preferred_slot');
        // El 20 en Lima todavía no empezó: es futuro, no «pasado» aunque en UTC ya sea hoy.
        $this->submit($parent, $student, ['preferred_date' => '2026-11-20', 'preferred_slot' => 'morning'])->assertSessionHasNoErrors();
        $this->assertSame(1, ClassRequest::count());
    }

    public function test_a_double_submit_creates_a_single_request_but_another_slot_is_a_new_one(): void
    {
        $this->nowLima('2026-11-19 10:00');
        [$parent, $student] = $this->parentWithStudent();
        $payload = ['preferred_date' => '2026-11-20', 'preferred_slot' => 'afternoon'];

        $this->submit($parent, $student, $payload);
        $this->submit($parent, $student, $payload);
        $this->assertSame(1, ClassRequest::count(), 'El doble clic/reintento no duplica la solicitud.');

        $this->submit($parent, $student, ['preferred_slot' => 'evening'] + $payload);
        $this->assertSame(2, ClassRequest::count(), 'Otra franja es otra intención.');
    }

    public function test_the_create_page_hands_the_single_source_of_slots_and_lima_today(): void
    {
        $this->nowLima('2026-11-19 23:30'); // en UTC ya es el 20
        [$parent] = $this->parentWithStudent();

        $props = $this->actingAs($parent)->get(route('class-requests.create'))->assertOk()->inertiaPage()['props'];
        $this->assertSame('2026-11-19', $props['todayLima']);
        $this->assertSame(['morning', 'afternoon', 'evening'], array_column($props['slots'], 'value'));
        $this->assertSame(['Mañana', 'Tarde', 'Noche'], array_column($props['slots'], 'label'));
        $this->assertSame(60, $props['maxDaysAhead']);
    }

    // ── El profesor propone la hora; el padre confirma ──────────────────────

    private function dated(): array
    {
        $this->nowLima('2026-11-19 10:00');
        [$teacher, $profile] = $this->teacher();
        [$parent, $student] = $this->parentWithStudent();
        $this->submit($parent, $student, ['preferred_date' => '2026-11-20', 'preferred_slot' => 'afternoon']);

        return [$teacher, $profile, $parent, ClassRequest::firstOrFail()];
    }

    private function propose(User $teacher, ClassRequest $request, string $limaLocal, int $minutes = 60)
    {
        return $this->actingAs($teacher)->post(route('teacher.requests.counteroffer', $request), [
            'counteroffer_time' => Carbon::parse($limaLocal, 'America/Lima')->utc()->toIso8601String(),
            'duration_minutes' => $minutes,
        ]);
    }

    public function test_the_teacher_cannot_book_a_dated_request_directly_it_must_be_confirmed_by_the_parent(): void
    {
        [$teacher, , , $request] = $this->dated();

        $this->actingAs($teacher)->get(route('teacher.requests.accept', $request))->assertRedirect(route('teacher.requests'));
        $this->actingAs($teacher)->post(route('lessons.store'), [
            'class_request_id' => $request->id,
            'start_time' => Carbon::parse('2026-11-20 16:00', 'America/Lima')->utc()->toIso8601String(),
            'duration_minutes' => 60,
        ])->assertSessionHasErrors('accept');

        $this->assertSame(0, Lesson::count());
        $this->assertSame('open', $request->fresh()->status);
    }

    public function test_legacy_requests_without_a_day_can_still_be_accepted_directly(): void
    {
        $this->nowLima('2026-11-19 10:00');
        [$teacher] = $this->teacher();
        [, $student] = $this->parentWithStudent();
        $legacy = ClassRequest::create(['student_id' => $student->id, 'subject_id' => $this->subject->id, 'help_needed' => 'Antigua', 'preferred_times' => ['morning_weekday', 'flexible'], 'status' => 'open']);

        $this->assertNull($legacy->fresh()->preferred_date);
        $this->assertFalse($legacy->hasPreferredDay());
        $this->actingAs($teacher)->get(route('teacher.requests.accept', $legacy))->assertOk();
        $row = $this->actingAs($teacher)->get(route('teacher.requests'))->inertiaPage()['props']['requests'][0];
        $this->assertNull($row['preference_label']);
        $this->assertSame(['morning_weekday', 'flexible'], $row['preferred_times']);
        $this->assertFalse($row['counteroffer_outside_preference']);
    }

    public function test_the_proposal_inside_the_slot_is_confirmed_by_the_parent_and_the_hour_is_the_same_everywhere(): void
    {
        [$teacher, , $parent, $request] = $this->dated();

        $this->propose($teacher, $request, '2026-11-20 16:00')->assertSessionHasNoErrors();
        $request->refresh();
        $this->assertSame('counteroffered', $request->status);
        $this->assertTrue($request->proposalFitsPreference($request->counteroffer_time));
        $this->assertSame(0, Lesson::count(), 'Proponer no agenda nada.');

        $summary = $this->actingAs($parent)->get(route('class-requests.index'))->inertiaPage()['props']['requests'][0];
        $this->assertFalse($summary['counteroffer_outside_preference']);
        $this->assertSame('viernes 20 de noviembre, por la tarde', $summary['preference_label']);

        $this->actingAs($parent)->post(route('class-requests.counteroffer.accept', $request), ['counteroffer_ref' => $request->counterofferRef()])
            ->assertSessionHasNoErrors();

        $lesson = Lesson::firstOrFail();
        $this->assertSame('2026-11-20T21:00:00+00:00', $lesson->start_time->utc()->toIso8601String(), '16:00 Lima = 21:00 UTC, convertido una sola vez.');
        $this->assertSame('20/11/2026 16:00 (hora de Perú)', LimaClock::dateTime($lesson->start_time), 'WhatsApp/correo muestran la misma hora.');
        $this->assertSame('accepted', $request->fresh()->status);
    }

    public function test_a_proposal_outside_the_slot_needs_the_parents_express_acceptance(): void
    {
        [$teacher, , $parent, $request] = $this->dated();

        $this->propose($teacher, $request, '2026-11-20 19:00')->assertSessionHasNoErrors(); // la tarde termina a las 18:00
        $request->refresh();
        $this->assertFalse($request->proposalFitsPreference($request->counteroffer_time));
        $this->assertTrue($this->actingAs($parent)->get(route('class-requests.index'))->inertiaPage()['props']['requests'][0]['counteroffer_outside_preference']);

        $this->actingAs($parent)->post(route('class-requests.counteroffer.accept', $request), ['counteroffer_ref' => $request->counterofferRef()])
            ->assertSessionHasErrors('counteroffer');
        $this->assertSame(0, Lesson::count(), 'Nunca se confirma en silencio un horario distinto del pedido.');
        $this->assertSame('counteroffered', $request->fresh()->status);

        $this->actingAs($parent)->post(route('class-requests.counteroffer.accept', $request), ['counteroffer_ref' => $request->counterofferRef(), 'confirm_outside_preference' => true])
            ->assertSessionHasNoErrors();
        $this->assertSame('2026-11-21T00:00:00+00:00', Lesson::firstOrFail()->start_time->utc()->toIso8601String());
    }

    public function test_a_proposal_on_another_day_is_also_an_alternative(): void
    {
        [$teacher, , $parent, $request] = $this->dated();

        $this->propose($teacher, $request, '2026-11-21 16:00')->assertSessionHasNoErrors();
        $request->refresh();
        $this->actingAs($parent)->post(route('class-requests.counteroffer.accept', $request), ['counteroffer_ref' => $request->counterofferRef()])
            ->assertSessionHasErrors('counteroffer');
        $this->assertSame(0, Lesson::count());
    }

    public function test_slot_boundaries_are_start_inclusive_and_end_exclusive(): void
    {
        [, , , $request] = $this->dated();
        $fits = fn (string $local) => $request->proposalFitsPreference(Carbon::parse($local, 'America/Lima')->utc());

        $this->assertTrue($fits('2026-11-20 12:00'));
        $this->assertTrue($fits('2026-11-20 17:59'));
        $this->assertFalse($fits('2026-11-20 18:00'));
        $this->assertFalse($fits('2026-11-20 11:59'));
    }
}
