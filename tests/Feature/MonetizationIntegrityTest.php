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
        $this->assertSame(3, $profile->fresh()->credits_available);
        $this->assertNotNull($lesson->jitsi_room);
        $this->assertStringStartsWith("mova-lesson-{$lesson->id}-", $lesson->jitsi_room);
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
        $this->assertSame(2, $profile->fresh()->credits_reserved);
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

        $this->actingAs($parent)->post(route('reviews.store', $lesson), ['rating' => 5])
            ->assertRedirect(route('parent.lessons'));
        $this->actingAs($parent)->post(route('reviews.store', $lesson), ['rating' => 4])
            ->assertStatus(403);

        $profile->refresh();
        $this->assertSame('completed', $lesson->fresh()->status);
        $this->assertSame(0, $profile->credits_reserved);
        $this->assertSame(1, $profile->completed_classes_count);
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "lesson:{$lesson->id}:consumption")->count());
        $this->assertSame(1, \App\Models\TeacherReview::where('lesson_id', $lesson->id)->count());
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
            'phone_verification_code_hash'  => Hash::make($code),
            'phone_verification_expires_at' => now()->addMinutes(10),
            'phone_verification_attempts'   => 0,
        ]);

        $this->actingAs($parent)->post(route('phone.verification.verify'), ['code' => $code])
            ->assertRedirect(route('dashboard'));

        $this->assertNotNull($parent->fresh()->phone_verified_at);
        $this->assertDatabaseCount('credit_transactions', 0);
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

    private function lesson(string $status, Carbon $startTime, int $reservedCredits = 2): array
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
            'amount' => 2,
            'description' => 'Reserva por aceptación de clase',
        ]);

        return [$teacher, $profile, $lesson, $parent];
    }

    private function reportPayload(): array
    {
        return [
            'topic_covered' => 'Ecuaciones lineales',
            'student_performance' => 'Resolvió los ejercicios propuestos.',
        ];
    }
}
