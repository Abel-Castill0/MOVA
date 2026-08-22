<?php

namespace Tests\Feature;

use App\Events\ClassConfirmed;
use App\Models\ClassEvent;
use App\Models\ClassRequest;
use App\Models\CreditTransaction;
use App\Models\Lesson;
use App\Models\LessonReport;
use App\Models\RechargeRequest;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MonetizationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        Notification::fake();
        Event::fake([ClassConfirmed::class]);
        config([
            'credits.recharges.enabled' => true,
            'credits.recharges.payment_destination' => 'TEST-DESTINATION',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_recharge_uses_server_catalog_and_ignores_client_financial_values(): void
    {
        [$teacher, $profile] = $this->teacher();

        $this->actingAs($teacher)->post(route('teacher.credits.recharge'), [
            'package_code' => 'inicio',
            'payment_method' => 'yape',
            'operation_number' => '000-1234',
            'package_name' => 'Manipulado',
            'credits' => 999,
            'amount_pen' => 0.01,
        ])->assertRedirect(route('teacher.credits.index'));

        $this->assertDatabaseHas('recharge_requests', [
            'teacher_profile_id' => $profile->id,
            'package_code' => 'inicio',
            'package_name' => 'Inicio',
            'credits' => 5,
            'amount_pen' => 10,
            'operation_number_normalized' => '0001234',
        ]);
    }

    public function test_invalid_package_code_fails(): void
    {
        [$teacher] = $this->teacher();

        $this->actingAs($teacher)->post(route('teacher.credits.recharge'), [
            'package_code' => 'inventado',
            'payment_method' => 'yape',
            'operation_number' => 'A10001',
        ])->assertSessionHasErrors('package_code');

        $this->assertDatabaseCount('recharge_requests', 0);
    }

    public function test_disabled_recharges_are_blocked_by_backend(): void
    {
        [$teacher] = $this->teacher();
        config(['credits.recharges.enabled' => false]);

        $this->actingAs($teacher)->post(route('teacher.credits.recharge'), [
            'package_code' => 'inicio',
            'payment_method' => 'yape',
            'operation_number' => 'A10002',
        ])->assertStatus(503);

        $this->assertDatabaseCount('recharge_requests', 0);
    }

    public function test_normalized_operation_number_cannot_be_reused(): void
    {
        [$teacher] = $this->teacher();

        $payload = [
            'package_code' => 'inicio',
            'payment_method' => 'yape',
            'operation_number' => ' 00-01 234 ',
        ];
        $this->actingAs($teacher)->post(route('teacher.credits.recharge'), $payload)
            ->assertSessionHasNoErrors();

        $payload['operation_number'] = '0001.234';
        $this->actingAs($teacher)->post(route('teacher.credits.recharge'), $payload)
            ->assertSessionHasErrors('operation_number');

        $this->assertDatabaseCount('recharge_requests', 1);
    }

    public function test_legacy_operation_number_cannot_be_reused_under_a_new_method(): void
    {
        [$teacher, $profile] = $this->teacher();
        RechargeRequest::create([
            'teacher_profile_id' => $profile->id,
            'package_code' => 'legacy',
            'package_name' => 'Histórico',
            'credits' => 5,
            'amount_pen' => '10.00',
            'payment_method' => 'legacy',
            'operation_number' => '000-7788',
            'operation_number_normalized' => '0007788',
            'status' => 'pending',
        ]);

        $this->actingAs($teacher)->post(route('teacher.credits.recharge'), [
            'package_code' => 'inicio',
            'payment_method' => 'yape',
            'operation_number' => '000 7788',
        ])->assertSessionHasErrors('operation_number');

        $this->assertDatabaseCount('recharge_requests', 1);
    }

    public function test_recharge_approval_adds_credits_once(): void
    {
        [$teacher, $profile] = $this->teacher();
        $admin = $this->userWithRole('admin');
        $recharge = $this->recharge($profile);

        $this->actingAs($admin)->post(route('admin.recharges.approve', $recharge))
            ->assertSessionHasNoErrors();

        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertDatabaseHas('recharge_requests', [
            'id' => $recharge->id,
            'status' => 'approved',
            'reviewed_by' => $admin->id,
        ]);
    }

    public function test_second_recharge_approval_does_not_duplicate_balance_or_ledger(): void
    {
        [, $profile] = $this->teacher();
        $admin = $this->userWithRole('admin');
        $recharge = $this->recharge($profile);

        $this->actingAs($admin)->post(route('admin.recharges.approve', $recharge));
        $this->actingAs($admin)->post(route('admin.recharges.approve', $recharge));

        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "recharge:{$recharge->id}:deposit")->count());
    }

    public function test_two_admin_approval_attempts_leave_one_deposit(): void
    {
        [, $profile] = $this->teacher();
        $firstAdmin = $this->userWithRole('admin');
        $secondAdmin = $this->userWithRole('admin');
        $recharge = $this->recharge($profile);

        $this->actingAs($firstAdmin)->post(route('admin.recharges.approve', $recharge));
        $this->actingAs($secondAdmin)->post(route('admin.recharges.approve', $recharge));

        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertDatabaseCount('credit_transactions', 1);
    }

    public function test_pending_recharge_with_existing_idempotency_key_requires_manual_review(): void
    {
        [, $profile] = $this->teacher();
        $admin = $this->userWithRole('admin');
        $recharge = $this->recharge($profile);
        CreditTransaction::create([
            'teacher_profile_id' => $profile->id,
            'recharge_request_id' => $recharge->id,
            'idempotency_key' => "recharge:{$recharge->id}:deposit",
            'type' => 'deposit',
            'amount' => $recharge->credits,
            'description' => 'Fixture de inconsistencia',
        ]);

        $this->actingAs($admin)->post(route('admin.recharges.approve', $recharge))
            ->assertStatus(409);

        $this->assertSame('pending', $recharge->fresh()->status);
        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertDatabaseCount('credit_transactions', 1);
    }

    public function test_recharge_rejection_requires_reason(): void
    {
        [, $profile] = $this->teacher();
        $admin = $this->userWithRole('admin');
        $recharge = $this->recharge($profile);

        $this->actingAs($admin)->post(route('admin.recharges.reject', $recharge), [])
            ->assertSessionHasErrors('reason');

        $this->assertSame('pending', $recharge->fresh()->status);
    }

    public function test_rejected_recharge_cannot_be_approved(): void
    {
        [, $profile] = $this->teacher();
        $admin = $this->userWithRole('admin');
        $recharge = $this->recharge($profile);

        $this->actingAs($admin)->post(route('admin.recharges.reject', $recharge), [
            'reason' => 'El comprobante no coincide con el pago informado.',
        ]);
        $this->actingAs($admin)->post(route('admin.recharges.approve', $recharge))
            ->assertStatus(422);

        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertDatabaseCount('credit_transactions', 0);
    }

    public function test_accepted_request_cannot_be_accepted_again(): void
    {
        [$teacher, $profile, $subject] = $this->teacher(5);
        [, , $request] = $this->parentRequest($subject, 'open');

        $payload = [
            'class_request_id' => $request->id,
            'start_time' => now()->addDay()->toDateTimeString(),
            'duration_minutes' => 60,
        ];

        $this->actingAs($teacher)->post(route('lessons.store'), $payload)
            ->assertRedirect(route('teacher.lessons'));
        $this->actingAs($teacher)->post(route('lessons.store'), $payload)
            ->assertStatus(403);

        $lesson = Lesson::firstOrFail();
        $this->assertSame('accepted', $request->fresh()->status);
        $this->assertSame(1, Lesson::count());
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "lesson:{$lesson->id}:reservation")->count());
        $this->assertSame(5 - Lesson::creditCostForMinutes(60), $profile->fresh()->credits_available);
        $this->assertNotNull($lesson->jitsi_room);
        $this->assertStringStartsWith("mova-lesson-{$lesson->id}-", $lesson->jitsi_room);
    }

    /**
     * @dataProvider durationCreditCases
     */
    public function test_lesson_credit_cost_scales_with_duration(int $durationMinutes, int $expectedCredits): void
    {
        [$teacher, $profile, $subject] = $this->teacher(10);
        $profile->update(['hourly_rate' => 20]);
        [, , $request] = $this->parentRequest($subject, 'open');

        $this->actingAs($teacher)->post(route('lessons.store'), [
            'class_request_id' => $request->id,
            'start_time' => now()->addDay()->toDateTimeString(),
            'duration_minutes' => $durationMinutes,
        ])->assertRedirect(route('teacher.lessons'));

        $lesson = Lesson::firstOrFail();

        $this->assertSame($expectedCredits, $lesson->credit_cost);
        $this->assertSame(10 - $expectedCredits, $profile->fresh()->credits_available);
        $this->assertSame($expectedCredits, $profile->fresh()->credits_reserved);
        $this->assertDatabaseHas('credit_transactions', [
            'lesson_id' => $lesson->id,
            'type' => 'reservation',
            'amount' => $expectedCredits,
        ]);
        // hourly_rate=20, ej. 2h30 (150 min) → 3 créditos → S/60, no S/50 (proporcional exacto).
        $this->assertEquals(20 * $expectedCredits, $lesson->price_frozen_pen);
    }

    public static function durationCreditCases(): array
    {
        return [
            '30 min = 1 crédito' => [30, 1],
            '1h = 1 crédito' => [60, 1],
            '2h = 2 créditos' => [120, 2],
            '2h30 = 3 créditos' => [150, 3],
        ];
    }

    public function test_cancelling_a_multi_hour_lesson_refunds_the_full_reserved_amount(): void
    {
        [$teacher, $profile, $subject] = $this->teacher(10);
        [, , $request] = $this->parentRequest($subject, 'open');

        $this->actingAs($teacher)->post(route('lessons.store'), [
            'class_request_id' => $request->id,
            'start_time' => now()->addDay()->toDateTimeString(),
            'duration_minutes' => 150,
        ]);

        $lesson = Lesson::firstOrFail();
        $this->assertSame(3, $lesson->credit_cost);
        $this->assertSame(7, $profile->fresh()->credits_available);
        $this->assertSame(3, $profile->fresh()->credits_reserved);

        $this->actingAs($teacher)->post(route('lessons.cancel', $lesson))
            ->assertRedirect();

        $this->assertSame(10, $profile->fresh()->credits_available);
        $this->assertSame(0, $profile->fresh()->credits_reserved);
        $this->assertDatabaseHas('credit_transactions', [
            'lesson_id' => $lesson->id,
            'type' => 'refund',
            'amount' => 3,
        ]);
    }

    public function test_lesson_start_with_timezone_offset_is_stored_and_guarded_in_utc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-11 12:00:00 UTC'));
        [$teacher, , $subject] = $this->teacher(5);
        [$parent, , $request] = $this->parentRequest($subject, 'open');

        $this->actingAs($teacher)->post(route('lessons.store'), [
            'class_request_id' => $request->id,
            'start_time' => '2026-07-11T10:00:00-05:00',
            'duration_minutes' => 60,
        ])->assertRedirect(route('teacher.lessons'));

        $lesson = Lesson::firstOrFail();
        $this->assertSame('2026-07-11 15:00:00', $lesson->start_time->utc()->format('Y-m-d H:i:s'));

        Carbon::setTestNow(Carbon::parse('2026-07-11 15:30:00 UTC'));
        $this->actingAs($parent)->post(route('lessons.confirm-payment', $lesson))
            ->assertStatus(422);
    }

    public function test_parent_cannot_approve_request_outside_pending_state(): void
    {
        $subject = Subject::create(['name' => 'Álgebra', 'level' => 'secundaria']);
        [$parent, , $request] = $this->parentRequest($subject, 'open');

        $this->actingAs($parent)->post(route('class-requests.approve', $request))
            ->assertStatus(422);

        $this->assertSame('open', $request->fresh()->status);
    }

    public function test_parent_cannot_reject_request_outside_pending_state(): void
    {
        $subject = Subject::create(['name' => 'Geometría', 'level' => 'secundaria']);
        [$parent, , $request] = $this->parentRequest($subject, 'accepted');

        $this->actingAs($parent)->post(route('class-requests.reject', $request))
            ->assertStatus(422);

        $this->assertSame('accepted', $request->fresh()->status);
    }

    public function test_cancelled_lesson_payment_cannot_be_confirmed(): void
    {
        [, , $lesson, $parent] = $this->lesson('cancelled', now()->subHours(2));

        $this->actingAs($parent)->post(route('lessons.confirm-payment', $lesson))
            ->assertStatus(422);

        $this->assertDatabaseCount('credit_transactions', 1);
    }

    public function test_completed_lesson_cannot_be_cancelled(): void
    {
        [$teacher, , $lesson] = $this->lesson('completed', now()->subHours(2), 0);

        $this->actingAs($teacher)->post(route('lessons.cancel', $lesson))
            ->assertStatus(422);

        $this->assertSame('completed', $lesson->fresh()->status);
    }

    public function test_future_lesson_payment_cannot_be_confirmed(): void
    {
        [, $profile, $lesson, $parent] = $this->lesson('scheduled', now()->addHour());

        $this->actingAs($parent)->post(route('lessons.confirm-payment', $lesson))
            ->assertStatus(422);

        $this->assertSame('scheduled', $lesson->fresh()->status);
        $this->assertSame(Lesson::creditCostForMinutes(60), $profile->fresh()->credits_reserved);
        $this->assertSame(0, $profile->fresh()->completed_classes_count);
    }

    public function test_confirming_payment_twice_does_not_duplicate_status_change(): void
    {
        [, , $lesson, $parent] = $this->lesson('scheduled', now()->subHours(2));

        $this->actingAs($parent)->post(route('lessons.confirm-payment', $lesson))
            ->assertRedirect();
        $this->actingAs($parent)->post(route('lessons.confirm-payment', $lesson))
            ->assertStatus(422);

        $this->assertSame('paid', $lesson->fresh()->status);
        $this->assertSame(1, ClassEvent::where('event_type', 'payment_confirmed')->count());
    }

    public function test_confirm_payment_time_gate_has_no_http_reachable_bypass(): void
    {
        [, $profile, $lesson, $parent] = $this->lesson('scheduled', now()->addHour());

        // Common backdoor shapes an attacker (or a careless future PR) might try:
        // query param, form field, header. None of them should move the needle —
        // confirmPayment() has no time-check bypass of any kind, in any environment.
        $this->actingAs($parent)
            ->post(route('lessons.confirm-payment', $lesson).'?bypass_time_check=1')
            ->assertStatus(422);

        $this->actingAs($parent)
            ->post(route('lessons.confirm-payment', $lesson), ['bypass_time_check' => true])
            ->assertStatus(422);

        $this->assertSame('scheduled', $lesson->fresh()->status);
        $this->assertSame(Lesson::creditCostForMinutes(60), $profile->fresh()->credits_reserved);
    }

    public function test_testing_backdate_lesson_command_unblocks_confirm_payment(): void
    {
        [, , $lesson, $parent] = $this->lesson('scheduled', now()->addHour());

        // Before backdating: the real-time gate still applies.
        $this->actingAs($parent)->post(route('lessons.confirm-payment', $lesson))
            ->assertStatus(422);

        $this->artisan('mova:testing-backdate-lesson', ['lesson_id' => $lesson->id])
            ->assertExitCode(0);

        $this->actingAs($parent)->post(route('lessons.confirm-payment', $lesson))
            ->assertRedirect();

        $this->assertSame('paid', $lesson->fresh()->status);
    }

    public function test_report_requires_a_paid_lesson(): void
    {
        [$teacher, , $lesson] = $this->lesson('scheduled', now()->subHours(2));

        $this->actingAs($teacher)->post(route('lesson-reports.store', $lesson), $this->reportPayload())
            ->assertStatus(422);

        $this->assertSame('scheduled', $lesson->fresh()->status);
        $this->assertDatabaseCount('lesson_reports', 0);
    }

    public function test_report_advances_paid_lesson_to_pending_parent_confirmation_without_financial_side_effects(): void
    {
        [$teacher, $profile, $lesson] = $this->lesson('paid', now()->subHours(2), 0);
        $beforeTransactions = CreditTransaction::count();

        $this->actingAs($teacher)->post(route('lesson-reports.store', $lesson), $this->reportPayload())
            ->assertRedirect(route('lesson-reports.show', $lesson));

        $this->assertSame('pending_parent_confirmation', $lesson->fresh()->status);
        $this->assertSame($beforeTransactions, CreditTransaction::count());
        $this->assertTrue(LessonReport::where('lesson_id', $lesson->id)->exists());
        $this->assertSame(0, $profile->fresh()->credits_reserved);
    }

    public function test_review_completes_lesson_and_consumes_reserved_credit_once(): void
    {
        [, $profile, $lesson, $parent] = $this->lesson('pending_parent_confirmation', now()->subHours(2));
        $this->reportFor($lesson);

        $this->actingAs($parent)->post(route('reviews.store', $lesson), ['rating' => 5])
            ->assertRedirect(route('parent.lessons'));
        // C-1: 'completed' ahora SÍ es un status reseñable (ver assertReviewable()),
        // así que el segundo intento ya no lo rechaza el guard de status (403) —
        // llega hasta el guard real: "ya existe una reseña" (422), igual que el
        // resto de duplicados en este controlador.
        $this->actingAs($parent)->post(route('reviews.store', $lesson), ['rating' => 4])
            ->assertStatus(422);

        $profile->refresh();
        // credits_settled_at está deliberadamente fuera de $fillable (protección
        // contra escritura por fuera del servicio) — regresión real detectada
        // manualmente: LessonSettlementService usaba update() con mass
        // assignment, que la mass-assignment guard descartaba en SILENCIO
        // incluso desde el propio servicio autorizado. status SÍ es fillable,
        // así que ese assert por sí solo nunca la habría detectado. Ver el fix
        // (asignación directa + save()) en LessonSettlementService.
        $this->assertNotNull($lesson->fresh()->credits_settled_at);
        $this->assertSame('completed', $lesson->fresh()->status);
        $this->assertSame(0, $profile->credits_reserved);
        $this->assertSame(1, $profile->completed_classes_count);
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "lesson:{$lesson->id}:consumption")->count());
        $this->assertSame(1, \App\Models\TeacherReview::where('lesson_id', $lesson->id)->count());
    }

    public function test_review_consumes_the_full_reserved_amount_for_a_multi_hour_lesson(): void
    {
        [, $profile, $lesson, $parent] = $this->lesson('pending_parent_confirmation', now()->subHours(2), 3);
        $this->reportFor($lesson);

        $this->actingAs($parent)->post(route('reviews.store', $lesson), ['rating' => 5])
            ->assertRedirect(route('parent.lessons'));

        $this->assertSame(0, $profile->fresh()->credits_reserved);
        $this->assertDatabaseHas('credit_transactions', [
            'lesson_id' => $lesson->id,
            'type' => 'consumption',
            'amount' => 3,
        ]);
    }

    public function test_approved_deposit_has_idempotency_key(): void
    {
        [, $profile] = $this->teacher();
        $admin = $this->userWithRole('admin');
        $recharge = $this->recharge($profile);

        $this->actingAs($admin)->post(route('admin.recharges.approve', $recharge));

        $this->assertDatabaseHas('credit_transactions', [
            'teacher_profile_id' => $profile->id,
            'recharge_request_id' => $recharge->id,
            'idempotency_key' => "recharge:{$recharge->id}:deposit",
        ]);
    }

    public function test_admin_verifying_teacher_does_not_grant_credits(): void
    {
        [$teacher, $profile] = $this->teacher();
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->post(route('admin.teachers.verify', $profile))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertDatabaseCount('credit_transactions', 0);
    }

    public function test_phone_verification_grants_teacher_welcome_bonus_once(): void
    {
        [$teacher, $profile] = $this->teacher();
        $code = '123456';
        $teacher->update([
            'phone' => '987654321',
            'phone_verification_code_hash'  => Hash::make($code),
            'phone_verification_expires_at' => now()->addMinutes(10),
            'phone_verification_attempts'   => 0,
        ]);

        $this->actingAs($teacher)->post(route('phone.verification.verify'), ['code' => $code])
            ->assertRedirect(route('dashboard'));

        $this->assertNotNull($teacher->fresh()->phone_verified_at);
        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "teacher:{$profile->id}:welcome")->count());

        // Simulate a re-verification (e.g. phone changed and re-confirmed): the bonus must not duplicate.
        $teacher->update([
            'phone_verified_at'              => null,
            'phone_verification_code_hash'   => Hash::make($code),
            'phone_verification_expires_at'  => now()->addMinutes(10),
            'phone_verification_attempts'    => 0,
        ]);
        $this->actingAs($teacher)->post(route('phone.verification.verify'), ['code' => $code])
            ->assertRedirect(route('dashboard'));

        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "teacher:{$profile->id}:welcome")->count());
    }

    public function test_phone_verification_does_not_grant_credits_to_parents(): void
    {
        $parent = $this->userWithRole('parent');
        $code = '123456';
        $parent->update([
            'phone' => '987654322',
            'phone_verification_code_hash'  => Hash::make($code),
            'phone_verification_expires_at' => now()->addMinutes(10),
            'phone_verification_attempts'   => 0,
        ]);

        $this->actingAs($parent)->post(route('phone.verification.verify'), ['code' => $code])
            ->assertRedirect(route('dashboard'));

        $this->assertNotNull($parent->fresh()->phone_verified_at);
        $this->assertDatabaseCount('credit_transactions', 0);
    }

    // Hallazgo CRÍTICO de auditoría (2026-08-22): un mismo teléfono, en N
    // cuentas de profesor, cobraba el bono de bienvenida N veces — probado
    // empíricamente con 3 cuentas y 15 créditos gratis antes del fix.
    public function test_same_phone_cannot_verify_and_collect_the_welcome_bonus_on_a_second_teacher_account(): void
    {
        [$firstTeacher, $firstProfile] = $this->teacher();
        $code = '123456';
        $firstTeacher->update([
            'phone' => '987000111',
            'phone_verification_code_hash' => Hash::make($code),
            'phone_verification_expires_at' => now()->addMinutes(10),
        ]);
        $this->actingAs($firstTeacher)->post(route('phone.verification.verify'), ['code' => $code])
            ->assertRedirect(route('dashboard'));
        $this->assertSame(5, $firstProfile->fresh()->credits_available);

        // Mismo teléfono (distintos formatos: espacios, sin +51 — normalizePhone()
        // debe reconocerlos como el mismo número), segunda cuenta de profesor.
        [$secondTeacher, $secondProfile] = $this->teacher();
        $secondTeacher->update([
            'phone' => '987 000 111',
            'phone_verification_code_hash' => Hash::make($code),
            'phone_verification_expires_at' => now()->addMinutes(10),
        ]);

        // send() ya lo rechaza sin gastar Twilio — chequeo amistoso.
        $this->actingAs($secondTeacher)->post(route('phone.verification.send'))
            ->assertSessionHasErrors('phone');

        // La garantía real: aunque alguien fuerce verify() directamente
        // (saltándose send()), el UNIQUE de phone_verified_normalized bloquea.
        $this->actingAs($secondTeacher)->post(route('phone.verification.verify'), ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertNull($secondTeacher->fresh()->phone_verified_at);
        $this->assertSame(0, $secondProfile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "teacher:{$firstProfile->id}:welcome")->count());
        $this->assertDatabaseCount('credit_transactions', 1);
    }

    public function test_financial_history_survives_account_deletion_flow(): void
    {
        [$teacher, $profile, $subject] = $this->teacher(3);
        $originalEmail = $teacher->email;
        $teacher->createToken('account-deletion-review');
        DB::table('sessions')->insert([
            'id' => 'review-session',
            'user_id' => $teacher->id,
            'ip_address' => null,
            'user_agent' => null,
            'payload' => 'fixture',
            'last_activity' => now()->timestamp,
        ]);
        [, $student, $request] = $this->parentRequest($subject, 'accepted');
        $recharge = $this->recharge($profile);
        $lesson = Lesson::create([
            'teacher_profile_id' => $profile->id,
            'student_id' => $student->id,
            'class_request_id' => $request->id,
            'start_time' => now()->subDay(),
            'duration_minutes' => 60,
            'status' => 'completed',
        ]);
        $transaction = CreditTransaction::create([
            'teacher_profile_id' => $profile->id,
            'lesson_id' => $lesson->id,
            'idempotency_key' => "lesson:{$lesson->id}:consumption",
            'type' => 'consumption',
            'amount' => 2,
            'description' => 'Consumo por clase completada',
        ]);

        $this->actingAs($teacher)->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect('/');

        $teacher->refresh();
        $this->assertNotNull($teacher->suspended_at);
        $this->assertStringContainsString('@mova.invalid', $teacher->email);
        $this->assertDatabaseHas('credit_transactions', ['id' => $transaction->id]);
        $this->assertDatabaseHas('recharge_requests', ['id' => $recharge->id]);
        $this->assertDatabaseHas('classes', ['id' => $lesson->id]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $teacher->id,
        ]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $teacher->id]);
        $this->assertDatabaseMissing('model_has_roles', [
            'model_type' => User::class,
            'model_id' => $teacher->id,
        ]);
        $this->post('/login', [
            'email' => $originalEmail,
            'password' => 'password',
        ])->assertSessionHasErrors('email');
    }

    public function test_insufficient_credits_never_create_negative_balance(): void
    {
        [$teacher, $profile, $subject] = $this->teacher(0);
        [, , $request] = $this->parentRequest($subject, 'open');

        $this->actingAs($teacher)->post(route('lessons.store'), [
            'class_request_id' => $request->id,
            'start_time' => now()->addDay()->toDateTimeString(),
            'duration_minutes' => 60,
        ])->assertStatus(422);

        $profile->refresh();
        $this->assertSame(0, $profile->credits_available);
        $this->assertSame(0, $profile->credits_reserved);
        $this->assertDatabaseCount('classes', 0);
    }

    public function test_lesson_join_allows_owner_parent_and_assigned_teacher(): void
    {
        [$teacher, , $lesson, $parent] = $this->lesson('scheduled', now()->addHour());
        $lesson->update(['jitsi_room' => 'mova-lesson-test-room', 'jitsi_password' => 'secret123']);

        $parentResponse = $this->actingAs($parent)->get(route('lessons.join', $lesson))
            ->assertOk()
            ->assertJsonStructure(['jitsi_room', 'jitsi_token', 'jaas_app_id'])
            ->assertJson(['jitsi_room' => 'mova-lesson-test-room']);

        $teacherResponse = $this->actingAs($teacher)->get(route('lessons.join', $lesson))
            ->assertOk()
            ->assertJsonStructure(['jitsi_room', 'jitsi_token', 'jaas_app_id'])
            ->assertJson(['jitsi_room' => 'mova-lesson-test-room']);

        // El JWT debe reflejar quién es moderador (el profesor asignado) y
        // quién no (el padre) — JaaS decide permisos por este claim, no por
        // el password legacy que ya no se usa para el embed.
        $publicKey = openssl_pkey_get_details(openssl_pkey_get_private(config('jaas.private_key')))['key'];

        $parentPayload = (array) \Firebase\JWT\JWT::decode($parentResponse->json('jitsi_token'), new \Firebase\JWT\Key($publicKey, 'RS256'));
        $teacherPayload = (array) \Firebase\JWT\JWT::decode($teacherResponse->json('jitsi_token'), new \Firebase\JWT\Key($publicKey, 'RS256'));

        $this->assertSame('mova-lesson-test-room', $parentPayload['room']);
        $this->assertSame('jitsi', $parentPayload['aud']);
        $this->assertSame('chat', $parentPayload['iss']);
        $this->assertFalse($parentPayload['context']->user->moderator);
        $this->assertTrue($teacherPayload['context']->user->moderator);

        // JaaS rechaza el JWT con "Missing Key ID (kid)" si el header no lo
        // trae, aunque la firma sea válida — se verifica el header crudo
        // (JWT::decode() solo devuelve el payload, no el header).
        [$rawHeader] = explode('.', $parentResponse->json('jitsi_token'));
        $header = json_decode(base64_decode(strtr($rawHeader, '-_', '+/')), true);
        $this->assertSame(config('jaas.key_id'), $header['kid']);
        $this->assertSame('RS256', $header['alg']);
    }

    public function test_lesson_join_rejects_unrelated_parent_and_teacher(): void
    {
        [, , $lesson] = $this->lesson('scheduled', now()->addHour());
        $lesson->update(['jitsi_room' => 'mova-lesson-test-room', 'jitsi_password' => 'secret123']);

        $unrelatedParent = $this->userWithRole('parent');
        $this->actingAs($unrelatedParent)->get(route('lessons.join', $lesson))->assertForbidden();

        [$unrelatedTeacher] = $this->teacher();
        $this->actingAs($unrelatedTeacher)->get(route('lessons.join', $lesson))->assertForbidden();
    }

    public function test_lesson_join_rejects_guest(): void
    {
        [, , $lesson] = $this->lesson('scheduled', now()->addHour());
        $lesson->update(['jitsi_room' => 'mova-lesson-test-room', 'jitsi_password' => 'secret123']);

        $this->get(route('lessons.join', $lesson))->assertRedirect(route('login'));
    }

    public function test_lesson_join_rejects_invalid_lesson_status(): void
    {
        // 'cancelled' y 'completed' no están en la lista de estados permitidos
        // para unirse — ni siquiera el dueño legítimo puede entrar a una sala
        // de una clase cancelada o ya finalizada.
        foreach (['cancelled', 'completed'] as $status) {
            [$teacher, , $lesson, $parent] = $this->lesson($status, now()->subHours(2));
            $lesson->update(['jitsi_room' => 'mova-lesson-test-room', 'jitsi_password' => 'secret123']);

            $this->actingAs($parent)->get(route('lessons.join', $lesson))->assertForbidden();
            $this->actingAs($teacher)->get(route('lessons.join', $lesson))->assertForbidden();
        }
    }

    public function test_lesson_join_allows_scheduled_paid_and_pending_parent_confirmation(): void
    {
        foreach (['scheduled', 'paid', 'pending_parent_confirmation'] as $status) {
            [$teacher, , $lesson, $parent] = $this->lesson($status, now()->addHour());
            $lesson->update(['jitsi_room' => 'mova-lesson-test-room', 'jitsi_password' => 'secret123']);

            $this->actingAs($parent)->get(route('lessons.join', $lesson))->assertOk();
            $this->actingAs($teacher)->get(route('lessons.join', $lesson))->assertOk();
        }
    }

    public function test_lesson_join_returns_not_found_when_room_was_never_created(): void
    {
        [, , $lesson, $parent] = $this->lesson('scheduled', now()->addHour());
        // El helper lesson() no fija jitsi_room — simula una clase legacy o un
        // registro inconsistente donde nunca se generó la sala.

        $this->actingAs($parent)->get(route('lessons.join', $lesson))->assertNotFound();
    }

    public function test_lesson_listings_never_expose_jitsi_credentials(): void
    {
        [$teacher, , $lesson, $parent] = $this->lesson('scheduled', now()->addHour());
        $lesson->update(['jitsi_room' => 'mova-lesson-test-room', 'jitsi_password' => 'secret123']);

        $this->actingAs($parent)->get(route('parent.lessons'))->assertInertia(fn ($page) => $page
            ->where('lessons.0.has_jitsi_room', true)
            ->missing('lessons.0.jitsi_room')
            ->missing('lessons.0.jitsi_password')
        );

        $this->actingAs($teacher)->get(route('teacher.lessons'))->assertInertia(fn ($page) => $page
            ->where('lessons.0.has_jitsi_room', true)
            ->missing('lessons.0.jitsi_room')
            ->missing('lessons.0.jitsi_password')
        );
    }

    public function test_monetization_migration_aborts_before_schema_changes_for_legacy_duplicates(): void
    {
        [, $profile] = $this->teacher();
        $migration = require database_path('migrations/2026_07_10_000001_harden_monetization_records.php');
        $migration->down();

        foreach (['00-1234', '00 1234'] as $operation) {
            DB::table('recharge_requests')->insert([
                'teacher_profile_id' => $profile->id,
                'package_name' => 'Inicio',
                'credits' => 5,
                'amount_pen' => '10.00',
                'operation_number' => $operation,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        try {
            $migration->up();
            $this->fail('La migración debió abortar por operaciones históricas duplicadas.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('normalized duplicates: 1', $exception->getMessage());
            $this->assertFalse(Schema::hasColumn('recharge_requests', 'operation_number_normalized'));
            $this->assertDatabaseCount('recharge_requests', 2);
        }
    }

    public function test_monetization_rollback_refuses_to_discard_financial_history(): void
    {
        [, $profile] = $this->teacher();
        $this->recharge($profile);
        $migration = require database_path('migrations/2026_07_10_000001_harden_monetization_records.php');

        try {
            $migration->down();
            $this->fail('El rollback debió rechazar la pérdida de historial financiero.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('rollback aborted', $exception->getMessage());
            $this->assertTrue(Schema::hasColumn('recharge_requests', 'operation_number_normalized'));
            $this->assertDatabaseCount('recharge_requests', 1);
        }
    }

    public function test_hourly_rate_auto_upgrades_through_tiers_as_reviews_come_in(): void
    {
        [, $profile, $subject] = $this->teacher();
        $profile->update(['credits_reserved' => 100]);

        $this->assertSame('0.00', $profile->fresh()->hourly_rate);

        // 5 clases calificadas con 5 estrellas cruzan el umbral de Experto (25).
        for ($i = 0; $i < 5; $i++) {
            $this->reviewedLesson($profile, $subject, 5);
        }
        $profile->refresh();
        $this->assertSame(5, $profile->completed_classes_count);
        $this->assertSame('25.00', $profile->hourly_rate);

        // 15 clases más (20 en total), siempre con 5 estrellas, cruzan Élite (30).
        for ($i = 0; $i < 15; $i++) {
            $this->reviewedLesson($profile, $subject, 5);
        }
        $profile->refresh();
        $this->assertSame(20, $profile->completed_classes_count);
        $this->assertSame('30.00', $profile->hourly_rate);
    }

    public function test_hourly_rate_stays_at_base_tier_without_enough_average_rating(): void
    {
        [, $profile, $subject] = $this->teacher();
        $profile->update(['credits_reserved' => 100]);

        // 5 clases completadas pero con calificación baja: no alcanza Experto (25).
        for ($i = 0; $i < 5; $i++) {
            $this->reviewedLesson($profile, $subject, 3);
        }
        $profile->refresh();
        $this->assertSame(5, $profile->completed_classes_count);
        $this->assertSame('20.00', $profile->hourly_rate);
    }

    private function teacher(int $availableCredits = 0): array
    {
        $teacher = $this->userWithRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_verified' => true,
            'credits_available' => $availableCredits,
            'credits_reserved' => 0,
        ]);
        $subject = Subject::create([
            'name' => 'Materia '.fake()->unique()->numerify('####'),
            'level' => 'secundaria',
        ]);
        $profile->subjects()->attach($subject->id);

        return [$teacher, $profile, $subject];
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['password' => 'password']);
        $user->assignRole($role);

        return $user;
    }

    private function parentRequest(Subject $subject, string $status): array
    {
        $parent = $this->userWithRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Alumno',
            'last_name' => 'Prueba',
            'grade_level' => 'secundaria',
        ]);
        $request = ClassRequest::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Necesita reforzar el tema.',
            'status' => $status,
        ]);

        return [$parent, $student, $request];
    }

    private function recharge(TeacherProfile $profile): RechargeRequest
    {
        $operation = fake()->unique()->numerify('OP########');

        return RechargeRequest::create([
            'teacher_profile_id' => $profile->id,
            'package_code' => 'inicio',
            'package_name' => 'Inicio',
            'credits' => 5,
            'amount_pen' => '10.00',
            'payment_method' => 'yape',
            'operation_number' => $operation,
            'operation_number_normalized' => $operation,
            'status' => 'pending',
        ]);
    }

    private function lesson(string $status, Carbon $startTime, int $reservedCredits = 1): array
    {
        [$teacher, $profile, $subject] = $this->teacher();
        $profile->update(['credits_reserved' => $reservedCredits]);
        [$parent, $student, $request] = $this->parentRequest($subject, 'accepted');
        $lesson = Lesson::create([
            'teacher_profile_id' => $profile->id,
            'student_id' => $student->id,
            'class_request_id' => $request->id,
            'start_time' => $startTime,
            'duration_minutes' => 60,
            'status' => $status,
        ]);

        CreditTransaction::create([
            'teacher_profile_id' => $profile->id,
            'lesson_id' => $lesson->id,
            'idempotency_key' => "lesson:{$lesson->id}:reservation",
            'type' => 'reservation',
            'amount' => $reservedCredits,
            'description' => 'Reserva por aceptación de clase',
        ]);

        return [$teacher, $profile, $lesson, $parent];
    }

    /** Crea el LessonReport que en producción siempre precede a pending_parent_confirmation. */
    private function reportFor(Lesson $lesson): LessonReport
    {
        return LessonReport::create([
            'lesson_id' => $lesson->id,
            'teacher_profile_id' => $lesson->teacher_profile_id,
            'student_id' => $lesson->student_id,
            'topic_covered' => 'Tema de prueba',
            'student_performance' => 'Buen desempeño',
            'sent_to_parent_at' => now(),
        ]);
    }

    private function reviewedLesson(TeacherProfile $profile, Subject $subject, int $rating): Lesson
    {
        [$parent, $student, $request] = $this->parentRequest($subject, 'accepted');

        $lesson = Lesson::create([
            'teacher_profile_id' => $profile->id,
            'student_id' => $student->id,
            'class_request_id' => $request->id,
            'start_time' => now()->subHour(),
            'duration_minutes' => 60,
            'status' => 'pending_parent_confirmation',
        ]);

        CreditTransaction::create([
            'teacher_profile_id' => $profile->id,
            'lesson_id' => $lesson->id,
            'idempotency_key' => "lesson:{$lesson->id}:reservation",
            'type' => 'reservation',
            'amount' => 1,
            'description' => 'Reserva por aceptación de clase',
        ]);

        // pending_parent_confirmation solo se alcanza en producción vía
        // LessonReportController::store(), que crea el reporte en el mismo
        // paso — así que el fixture debe reflejar esa invariante. El gate de
        // C-1 en TeacherReviewController::assertReviewable() ahora la exige
        // explícitamente (antes no hacía falta: antes de C-1,
        // pending_parent_confirmation la implicaba por construcción).
        LessonReport::create([
            'lesson_id' => $lesson->id,
            'teacher_profile_id' => $profile->id,
            'student_id' => $student->id,
            'topic_covered' => 'Tema de prueba',
            'student_performance' => 'Buen desempeño',
            'sent_to_parent_at' => now(),
        ]);

        $this->actingAs($parent)
            ->post(route('reviews.store', $lesson), ['rating' => $rating])
            ->assertRedirect(route('parent.lessons'));

        return $lesson->fresh();
    }

    private function reportPayload(): array
    {
        return [
            'topic_covered' => 'Ecuaciones lineales',
            'student_performance' => 'Resolvió los ejercicios propuestos.',
        ];
    }
}
