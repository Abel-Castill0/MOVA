<?php

namespace Tests\Feature;

use App\Models\{AccountDeletionRequest, ClassRequest, CreditTransaction, Lesson, PaymentOrder, RechargeRequest, Student, Subject, TeacherProfile, User};
use App\Services\{AccountLifecycleService, LessonSchedulingService};
use App\Notifications\AccountClosureCode;
use App\Listeners\PreventClosedAccountNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\{Cache, DB, Hash, Notification};
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['parent', 'teacher', 'admin'] as $role) Role::findOrCreate($role, 'web');
    }

    private function user(string $role = 'parent'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        return $user;
    }

    private function closure(array $extra = []): array
    {
        return array_replace(['password' => 'password', 'confirmation' => true, 'scope' => 'account'], $extra);
    }

    private function teacher(): array
    {
        $user = $this->user('teacher');
        $profile = TeacherProfile::create(['user_id' => $user->id, 'is_verified' => true, 'hourly_rate' => 20, 'credits_available' => 10, 'credits_reserved' => 0]);
        return [$user, $profile];
    }

    private function classRequest(User $parent, TeacherProfile $teacher): ClassRequest
    {
        $student = Student::create(['parent_user_id' => $parent->id, 'first_name' => 'Alumno QA', 'last_name' => 'Privado', 'grade_level' => 'primaria']);
        $subject = Subject::firstOrCreate(['name' => 'Matemáticas'], ['level' => 'Primaria']);
        $teacher->subjects()->sync([$subject->id]);
        return ClassRequest::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'duration_minutes' => 60, 'help_needed' => 'Práctica QA', 'status' => 'open']);
    }

    public function test_parent_and_teacher_edit_only_their_own_identity(): void
    {
        $other = $this->user();
        foreach (['parent', 'teacher'] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->patch('/profile', ['name' => 'Nombre actualizado', 'email' => $user->email, 'phone' => '987654321', 'id' => $other->id, 'account_closed_at' => now(), 'roles' => ['admin']])->assertSessionHasNoErrors();
            $this->assertSame('Nombre actualizado', $user->fresh()->name);
            $this->assertNull($user->fresh()->account_closed_at);
            $this->assertFalse($user->fresh()->hasRole('admin'));
        }
        $this->assertNotSame('Nombre actualizado', $other->fresh()->name);
    }

    public function test_email_change_requires_reauthentication_and_clears_verification(): void
    {
        $user = $this->user();
        $data = ['name' => $user->name, 'email' => 'changed@example.test'];
        $this->actingAs($user)->patch('/profile', $data)->assertSessionHasErrors('current_password');
        $this->patch('/profile', $data + ['current_password' => 'password'])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->email_verified_at);
        $this->actingAs($user->fresh());
        $this->get('/profile')->assertOk();
        $this->get('/dashboard')->assertOk();
    }

    public function test_password_change_uses_existing_current_password_validation(): void
    {
        $user = $this->user();
        $this->actingAs($user)->put('/password', ['current_password' => 'wrong', 'password' => 'AnotherPass123!', 'password_confirmation' => 'AnotherPass123!'])->assertSessionHasErrors('current_password');
        $this->put('/password', ['current_password' => 'password', 'password' => 'AnotherPass123!', 'password_confirmation' => 'AnotherPass123!'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('AnotherPass123!', $user->fresh()->password));
    }

    public function test_invalid_confirmation_password_and_scope_do_not_close_account(): void
    {
        $user = $this->user();
        $this->actingAs($user)->delete('/profile', $this->closure(['password' => 'wrong']))->assertSessionHasErrors('password');
        $this->delete('/profile', $this->closure(['confirmation' => false]))->assertSessionHasErrors('confirmation');
        $this->delete('/profile', $this->closure(['scope' => 'teacher']))->assertSessionHasErrors('scope');
        $this->assertNull($user->fresh()->account_closed_at);
        $this->assertDatabaseCount('account_deletion_requests', 0);
    }

    public function test_account_is_closed_and_erased_automatically_and_receives_completed_receipt(): void
    {
        $user = $this->user(); $email = $user->email;
        $this->actingAs($user)->delete('/profile', $this->closure())->assertRedirect(route('account.closure.status'));
        $this->assertGuest();
        $fresh = $user->fresh();
        $this->assertNotNull($fresh->account_closed_at);
        $this->assertNotNull($fresh->account_erased_at);
        $this->assertSame("deleted-{$user->id}@mova.invalid", $fresh->email);
        $this->assertNull($fresh->phone);
        $this->assertNull($fresh->avatar_url);
        $this->assertSame([], $fresh->getRoleNames()->all());
        $this->get(route('account.closure.status'))->assertInertia(fn ($page) => $page->where('receipt.status', 'completed')->where('accountSummary', null)->missing('receipt.snapshot'));
        $this->assertDatabaseCount('account_deletion_requests', 1);
        $this->assertDatabaseMissing('users', ['email' => $email]);
    }

    public function test_retry_is_idempotent_and_other_user_id_is_ignored(): void
    {
        $user = $this->user(); $other = $this->user();
        $this->actingAs($user)->delete('/profile', $this->closure(['user_id' => $other->id]))->assertRedirect();
        $first = AccountDeletionRequest::first();
        $again = app(AccountLifecycleService::class)->closeAccount($user->fresh(), 'password');
        $this->assertSame($first->id, $again->id);
        $this->assertNull($other->fresh()->account_closed_at);
        $this->assertDatabaseCount('account_deletion_requests', 1);
        $this->assertCount(2, $again->events);
    }

    public function test_admin_even_with_teacher_role_cannot_use_closure(): void
    {
        $admin = $this->user('admin'); $admin->assignRole('teacher');
        $this->actingAs($admin)->delete('/profile', $this->closure())->assertForbidden();
        $this->assertNull($admin->fresh()->account_closed_at);
    }

    public function test_multiple_roles_erase_pii_but_preserve_ledger_history_and_payments(): void
    {
        [$user, $teacher] = $this->teacher(); $user->assignRole('parent');
        $request = $this->classRequest($user, $teacher);
        $transaction = CreditTransaction::create(['teacher_profile_id' => $teacher->id, 'idempotency_key' => 'qa-deposit', 'type' => 'deposit', 'amount' => 10, 'description' => 'QA']);
        $lesson = app(LessonSchedulingService::class)->schedule($request->id, $teacher->id, now()->addDay()->toIso8601String(), 60, fn () => null);
        $recharge = RechargeRequest::create(['teacher_profile_id' => $teacher->id, 'package_code' => 'inicio', 'package_name' => 'Inicio', 'credits' => 5, 'amount_pen' => 10, 'payment_method' => 'culqi', 'status' => 'pending']);
        $order = PaymentOrder::create(['recharge_request_id' => $recharge->id, 'attempt_number' => 1, 'idempotency_key' => (string) Str::uuid(), 'provider' => 'culqi', 'status' => 'pending', 'amount_minor' => 1000, 'currency' => 'PEN', 'review_reason' => 'qa_review']);
        $ledgerBefore = $transaction->fresh()->getAttributes();
        // Losing unused credits requires explicit acceptance.
        $this->actingAs($user)->delete('/profile', $this->closure())->assertSessionHasErrors('accept_credit_loss');
        $this->assertNull($user->fresh()->account_closed_at);
        $this->assertSame(9, $teacher->fresh()->credits_available);

        $this->delete('/profile', $this->closure(['accept_credit_loss' => true]))->assertRedirect(route('account.closure.status'));
        $summary = AccountDeletionRequest::first()->snapshot;
        $this->assertSame(1, $summary['students']);
        $this->assertSame(1, $summary['future_classes']);
        $this->assertSame(10, $summary['credits_forfeitable']);
        $this->assertSame(1, $summary['pending_payments']);
        $this->assertEqualsCanonicalizing(['parent', 'teacher'], $summary['roles']);
        $this->assertSame('cancelled', $lesson->fresh()->status);
        $this->assertSame($user->id, $lesson->fresh()->cancelled_by);
        // Balance leaves through ledger entries, never a silent UPDATE: reservation refunded, then forfeited.
        $profile = $teacher->fresh();
        $this->assertSame(0, $profile->credits_available);
        $this->assertSame(0, $profile->credits_reserved);
        $this->assertSame($ledgerBefore, $transaction->fresh()->getAttributes());
        $this->assertSame(['deposit', 'forfeiture', 'refund', 'reservation'], CreditTransaction::where('teacher_profile_id', $teacher->id)->pluck('type')->sort()->values()->all());
        $this->assertSame(-10, (int) CreditTransaction::where('type', 'forfeiture')->value('amount'));
        $this->assertSame(0, app(\App\Support\LedgerReconciliation::class)->derivedAvailableFor($teacher->id));
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertDatabaseHas('recharge_requests', ['id' => $recharge->id]);
        $this->assertDatabaseCount('classes', 1);
        $student = Student::withTrashed()->find($request->student_id);
        $this->assertSame('Estudiante', $student->first_name);
        $this->assertNotNull($student->deleted_at);
        $this->assertSame('expired', $request->fresh()->status);
        $this->assertFalse((bool) $profile->is_verified);
    }

    public function test_credits_bought_within_the_refund_window_are_not_forfeited_and_raise_an_incident(): void
    {
        [$user, $teacher] = $this->teacher();
        RechargeRequest::create(['teacher_profile_id' => $teacher->id, 'package_code' => 'inicio', 'package_name' => 'Inicio', 'credits' => 4, 'amount_pen' => 8, 'payment_method' => 'yape', 'status' => 'approved', 'approved_at' => now()->subDays(2)]);
        $this->actingAs($user)->delete('/profile', $this->closure(['accept_credit_loss' => true]))->assertRedirect();
        $this->assertSame(4, $teacher->fresh()->credits_available);
        $this->assertSame(-6, (int) CreditTransaction::where('type', 'forfeiture')->value('amount'));
        $outcome = AccountDeletionRequest::first()->outcome;
        $this->assertSame(4, $outcome['credits_refundable']);
        $this->assertSame(6, $outcome['credits_forfeited']);
        $this->assertDatabaseHas('operational_alerts', ['alert_key' => "account-closure:{$user->id}:refundable-credits"]);
    }

    public function test_closure_with_no_unused_credits_needs_no_loss_acceptance_and_is_idempotent_on_ledger(): void
    {
        [$user, $teacher] = $this->teacher();
        $teacher->update(['credits_available' => 0]);
        $this->actingAs($user)->delete('/profile', $this->closure())->assertRedirect();
        $this->assertSame(0, CreditTransaction::where('type', 'forfeiture')->count());
        app(AccountLifecycleService::class)->closeAccount($user->fresh(), 'password', null, true);
        $this->assertSame(0, CreditTransaction::where('type', 'forfeiture')->count());
    }

    public function test_sessions_tokens_and_routine_notifications_are_revoked(): void
    {
        $user = $this->user();
        $user->forceFill(['whatsapp_opt_in_at' => now()])->save();
        $user->createToken('qa-session');
        DB::table('sessions')->insert(['id' => 'qa-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'x', 'created_at' => now()]);
        DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => User::class, 'notifiable_id' => $user->id, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        app(AccountLifecycleService::class)->closeAccount($user, 'password');
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $user->id)->count());
        $this->assertFalse($user->fresh()->wantsWhatsAppNotifications());
        // Routine notices are suppressed; the single-use proof (legacy reconfirmation) is the exception.
        $routine = new \App\Notifications\TeacherClosedAccountNotification(ClassRequest::make(['subject_id' => 0]), true);
        $this->assertFalse(app(PreventClosedAccountNotifications::class)->handle(new NotificationSending($user, $routine, 'mail')));
        $this->assertNull(app(PreventClosedAccountNotifications::class)->handle(new NotificationSending($user, new AccountClosureCode('123456'), 'mail')));
        $this->actingAs($user->fresh())->get('/dashboard')->assertRedirect(route('account.closure.status'));
        $this->post('/chatbot/message', ['message' => 'hola'])->assertRedirect(route('account.closure.status'));
        $this->get('/profile')->assertRedirect(route('account.closure.status'));
        $this->get('/cuenta/solicitud')->assertOk();
    }

    public function test_closure_does_not_require_accepting_new_legal_terms(): void
    {
        $user = $this->user();
        DB::table('legal_acceptances')->where('user_id', $user->id)->delete();
        $user->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($user)->get('/profile')->assertOk();
        $this->delete('/profile', $this->closure())->assertRedirect(route('account.closure.status'));
    }

    public function test_parent_closure_blocks_new_lessons_and_hides_active_requests(): void
    {
        [$teacherUser, $teacher] = $this->teacher(); $parent = $this->user();
        $request = $this->classRequest($parent, $teacher);
        app(AccountLifecycleService::class)->closeAccount($parent, 'password');
        $this->assertFalse($teacherUser->can('accept', $request->fresh()));
        $this->assertCount(0, $request->fresh()->eligibleTeacherUsers());
        $this->assertSame(0, ClassRequest::visibleToTeacher($teacher->id, [], [$request->subject_id])->count());
        try {
            app(LessonSchedulingService::class)->schedule($request->id, $teacher->id, now()->addDay()->toIso8601String(), 60, fn () => null);
            $this->fail('Closed account should block scheduling.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('accept', $exception->errors());
        }
        $this->assertDatabaseCount('classes', 0);
        $this->assertSame(10, $teacher->fresh()->credits_available);
    }

    private function scheduledLesson(User $parent, TeacherProfile $teacher, int $days = 2): Lesson
    {
        $request = $this->classRequest($parent, $teacher);

        return app(LessonSchedulingService::class)->schedule($request->id, $teacher->id, now()->addDays($days)->toIso8601String(), 60, fn () => null);
    }

    public function test_closed_parent_can_cancel_a_class_scheduled_before_closure_but_nothing_else(): void
    {
        [$teacherUser, $teacher] = $this->teacher(); $parent = $this->user();
        $lesson = $this->scheduledLesson($parent, $teacher);
        $other = $this->scheduledLesson($this->user(), $teacher, 4);
        $this->assertSame(8, $teacher->fresh()->credits_available);
        $this->legacyClosed($parent);

        $this->actingAs($parent->fresh())->get(route('account.closure.status'))->assertOk()->assertInertia(fn ($page) => $page
            ->has('cancellableLessons', 1)->where('cancellableLessons.0.id', $lesson->id)->missing('cancellableLessons.0.student'));
        $this->post(route('lessons.reschedule', $lesson), ['start_time' => now()->addDays(3)->toIso8601String()])->assertRedirect(route('account.closure.status'));
        $this->get(route('lessons.join', $lesson))->assertRedirect(route('account.closure.status'));
        $this->post(route('lessons.cancel', $other), ['reason' => 'x'])->assertForbidden();
        $this->assertSame('scheduled', $other->fresh()->status);

        $this->post(route('lessons.cancel', $lesson), ['reason' => 'Cuenta cerrada'])->assertRedirect();
        $this->assertSame('cancelled', $lesson->fresh()->status);
        $this->assertSame(9, $teacher->fresh()->credits_available);
        $this->assertSame(1, $teacher->fresh()->credits_reserved);
        $this->assertSame(1, CreditTransaction::where('lesson_id', $lesson->id)->where('type', 'refund')->count());
        // A repeated request is rejected and never refunds twice.
        $this->post(route('lessons.cancel', $lesson))->assertSessionHasErrors('cancel');
        $this->assertSame(9, $teacher->fresh()->credits_available);
        $this->assertNotNull($parent->fresh()->account_closed_at);
        $this->assertNotNull(Student::find($lesson->student_id));
        $this->get('/dashboard')->assertRedirect(route('account.closure.status'));
    }

    public function test_closed_teacher_can_cancel_only_her_own_classes_and_no_notice_reaches_her(): void
    {
        [$teacherUser, $teacher] = $this->teacher(); $parent = $this->user();
        $lesson = $this->scheduledLesson($parent, $teacher);
        [, $otherTeacher] = $this->teacher();
        $foreign = $this->scheduledLesson($this->user(), $otherTeacher);
        $noticesBefore = DB::table('notifications')->where('notifiable_id', $teacherUser->id)->count();
        $this->legacyClosed($teacherUser);

        $this->actingAs($teacherUser->fresh())->post(route('lessons.cancel', $foreign))->assertForbidden();
        $this->assertSame('scheduled', $foreign->fresh()->status);
        $this->post(route('lessons.cancel', $lesson))->assertRedirect();
        $this->assertSame('cancelled', $lesson->fresh()->status);
        $this->assertSame(10, $teacher->fresh()->credits_available);
        $this->assertSame(0, $teacher->fresh()->credits_reserved);
        $this->assertNotNull($lesson->student->parent->fresh());
        $this->assertSame($noticesBefore, DB::table('notifications')->where('notifiable_id', $teacherUser->id)->count());
    }

    public function test_closed_teacher_cannot_be_reverified_or_exposed_even_if_flag_is_restored(): void
    {
        [$user, $profile] = $this->teacher();
        app(AccountLifecycleService::class)->closeAccount($user, 'password', null, true);
        $this->actingAs($this->user('admin'))->post(route('admin.teachers.verify', $profile))->assertStatus(409);
        $profile->update(['is_verified' => true]);
        $this->get(route('teachers.show', $profile))->assertNotFound();
    }

    public function test_status_is_private_and_admin_note_reaches_a_legacy_request(): void
    {
        $user = $this->user(); $admin = $this->user('admin');
        $deletion = $this->legacyClosed($user);
        app(AccountLifecycleService::class)->respond($deletion, $admin, 'Revisamos las obligaciones pendientes. Contacta con soporte para coordinar.');
        $this->actingAs($user->fresh())->get('/cuenta/solicitud')->assertInertia(fn ($page) => $page->where('receipt.status', 'responded')->has('accountSummary'));
        $other = $this->user();
        $this->actingAs($other)->get('/cuenta/solicitud?user_id='.$user->id)->assertNotFound();
        $this->get('/admin/account-deletions')->assertForbidden();
        $this->patch(route('admin.account-deletions.update', $deletion), ['response' => 'No permitido para este usuario.'])->assertForbidden();
    }

    public function test_verified_email_proof_is_single_use_and_scoped_to_session(): void
    {
        config(['mail.default' => 'array', 'mail.allowlist' => '']);
        Notification::fake();
        $user = $this->user();
        $this->actingAs($user)->from('/profile')->post(route('account.closure.code'))->assertSessionHas('status', 'closure-code-sent');
        $code = null;
        Notification::assertSentTo($user, AccountClosureCode::class, function ($notification) use (&$code, $user) {
            preg_match('/\d{6}/', implode(' ', $notification->toMail($user)->introLines), $matches);
            $code = $matches[0]; return true;
        });
        $this->delete('/profile', $this->closure(['password' => '', 'code' => $code]))->assertRedirect(route('account.closure.status'));
        $this->assertSame('email_code', AccountDeletionRequest::first()->identity_method);
    }

    private function issueClosureCode(User $user): string
    {
        config(['mail.default' => 'array', 'mail.allowlist' => '']);
        Notification::fake();
        $this->actingAs($user)->from('/profile')->post(route('account.closure.code'))
            ->assertSessionHas('status', 'closure-code-sent');
        $notification = Notification::sent($user, AccountClosureCode::class)->sole();
        preg_match('/\d{6}/', implode(' ', $notification->toMail($user)->introLines), $matches);

        return $matches[0];
    }

    public function test_expired_email_code_cannot_close_account(): void
    {
        $user = $this->user();
        $code = $this->issueClosureCode($user);
        $this->travel(11)->minutes();
        $this->delete('/profile', $this->closure(['password' => '', 'code' => $code]))
            ->assertSessionHasErrors('password');
        $this->assertNull($user->fresh()->account_closed_at);
        $this->assertDatabaseCount('account_deletion_requests', 0);
    }

    public function test_code_from_one_session_is_rejected_in_another_session(): void
    {
        $user = $this->user();
        $code = $this->issueClosureCode($user);
        // Logout destroys the first session, including its proof nonce.
        $this->post('/logout')->assertRedirect('/');
        $this->actingAs($user)->delete('/profile', $this->closure(['password' => '', 'code' => $code]))
            ->assertSessionHasErrors('password');
        $this->assertNull($user->fresh()->account_closed_at);
        $this->assertDatabaseCount('account_deletion_requests', 0);
    }

    public function test_wrong_code_consumes_proof_and_valid_code_cannot_be_replayed(): void
    {
        $user = $this->user();
        $code = $this->issueClosureCode($user);
        $wrongCode = $code === '100000' ? '100001' : '100000';
        $this->delete('/profile', $this->closure(['password' => '', 'code' => $wrongCode]))
            ->assertSessionHasErrors('password');
        $this->delete('/profile', $this->closure(['password' => '', 'code' => $code]))
            ->assertSessionHasErrors('password');
        $this->assertNull($user->fresh()->account_closed_at);
        $this->assertDatabaseCount('account_deletion_requests', 0);

        // Resend and submit have separate rate-limit buckets.
        $newCode = $this->issueClosureCode($user);
        $this->delete('/profile', $this->closure(['password' => '', 'code' => $newCode]))
            ->assertRedirect(route('account.closure.status'));
        $this->assertDatabaseCount('account_deletion_requests', 1);
    }

    public function test_consumed_email_proof_cannot_be_reused_after_identity_changes(): void
    {
        $user = $this->user();
        $code = $this->issueClosureCode($user);
        $this->app->instance(AccountLifecycleService::class, new class extends AccountLifecycleService {
            public function closeAccount(User $user, string $identityMethod, ?\Closure $validateIdentity = null, bool|int $acceptCreditLoss = false): AccountDeletionRequest
            {
                User::whereKey($user->id)->update(['email' => 'changed-before-lock@example.test']);

                return parent::closeAccount($user, $identityMethod, $validateIdentity, $acceptCreditLoss);
            }
        });
        $this->delete('/profile', $this->closure(['password' => '', 'code' => $code]))
            ->assertSessionHasErrors('password');
        $this->assertNull($user->fresh()->account_closed_at);
        $this->assertDatabaseCount('account_deletion_requests', 0);

        // Even restoring the original identity cannot revive the consumed proof.
        User::whereKey($user->id)->update(['email' => $user->email]);
        $this->app->instance(AccountLifecycleService::class, new AccountLifecycleService);
        $this->actingAs($user->fresh())->delete('/profile', $this->closure(['password' => '', 'code' => $code]))
            ->assertSessionHasErrors('password');
        $this->assertDatabaseCount('account_deletion_requests', 0);
    }

    public function test_password_changed_before_lock_aborts_closure_without_revoking_tokens(): void
    {
        [$user, $teacher] = $this->teacher();
        $user->createToken('must-survive-rejected-closure');
        $this->app->instance(AccountLifecycleService::class, new class extends AccountLifecycleService {
            public function closeAccount(User $user, string $identityMethod, ?\Closure $validateIdentity = null, bool|int $acceptCreditLoss = false): AccountDeletionRequest
            {
                User::whereKey($user->id)->update(['password' => Hash::make('RotatedPass123!')]);

                return parent::closeAccount($user, $identityMethod, $validateIdentity, $acceptCreditLoss);
            }
        });
        $this->actingAs($user)->delete('/profile', $this->closure())->assertSessionHasErrors('password');
        $this->assertNull($user->fresh()->account_closed_at);
        $this->assertTrue(Hash::check('RotatedPass123!', $user->fresh()->password));
        $this->assertTrue($teacher->fresh()->is_verified);
        $this->assertSame(10, $teacher->fresh()->credits_available);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('account_deletion_requests', 0);
    }

    public function test_profile_and_settings_expose_distinct_sections_and_role_capabilities(): void
    {
        foreach (['parent', 'teacher', 'admin'] as $role) {
            $user = $this->user($role);
            foreach (['/profile' => 'personal', '/account/settings' => 'security'] as $url => $section) {
                $this->actingAs($user)->get($url)->assertOk()->assertInertia(fn ($page) => $page
                    ->component('Profile/Edit')
                    ->where('section', $section)
                    ->where('canRequestClosure', $role !== 'admin')
                    ->where('accountSummary.roles', [$role])
                    ->where('accountSummary.students', 0));
            }
        }
    }

    public function test_failed_mail_or_wrong_code_does_not_close_and_can_retry(): void
    {
        $user = $this->user();
        config(['mail.default' => 'log']);
        $this->actingAs($user)->post(route('account.closure.code'))->assertSessionHasErrors('code');
        $this->delete('/profile', $this->closure(['password' => '', 'code' => '000000']))->assertSessionHasErrors('password');
        $this->assertNull($user->fresh()->account_closed_at);
        $this->delete('/profile', $this->closure())->assertRedirect(route('account.closure.status'));
    }

    public function test_email_code_requires_verified_email_and_an_eligible_role(): void
    {
        config(['mail.default' => 'array', 'mail.allowlist' => '']);
        Notification::fake();
        $unverified = User::factory()->unverified()->create();
        $unverified->assignRole('parent');
        $this->actingAs($unverified)->post(route('account.closure.code'))->assertForbidden();
        $admin = $this->user('admin');
        $this->actingAs($admin)->post(route('account.closure.code'))->assertForbidden();
        $this->actingAs(User::factory()->create())->post(route('account.closure.code'))->assertForbidden();
        Notification::assertNothingSent();
        // An unverified email can never satisfy the identity check with a code.
        $this->actingAs($unverified)->delete('/profile', $this->closure(['password' => '', 'code' => '123456']))->assertSessionHasErrors('password');
        $this->assertNull($unverified->fresh()->account_closed_at);
        auth()->logout();
        $this->delete('/profile', $this->closure())->assertRedirect('/login');
        $this->post(route('account.closure.code'))->assertRedirect('/login');
    }

    public function test_code_and_closure_requests_are_rate_limited(): void
    {
        config(['mail.default' => 'array', 'mail.allowlist' => '']);
        Notification::fake();
        $user = $this->user();
        $this->actingAs($user);
        foreach (range(1, 3) as $attempt) {
            $this->post(route('account.closure.code'))->assertSessionHasNoErrors();
        }
        $this->post(route('account.closure.code'))->assertStatus(429);
        foreach (range(1, 5) as $attempt) {
            $this->delete('/profile', $this->closure(['password' => 'wrong-'.$attempt]))->assertSessionHasErrors('password');
        }
        $this->delete('/profile', $this->closure())->assertStatus(429);
        $this->assertNull($user->fresh()->account_closed_at);
    }

    public function test_queued_whatsapp_job_is_dropped_for_closed_account_but_not_for_others(): void
    {
        $closed = $this->user();
        $closed->forceFill(['phone' => '987654321', 'phone_verified_normalized' => '+51987654321', 'account_closed_at' => now()])->save();
        $other = $this->user();
        $other->forceFill(['phone' => '912345678', 'phone_verified_normalized' => '+51912345678'])->save();
        $service = \Mockery::mock(\App\Services\WhatsAppService::class);
        $service->shouldReceive('sendText')->once()->with('912345678', 'hola')->andReturn(['success' => true]);
        (new \App\Jobs\SendWhatsAppNotificationJob('987654321', 'hola'))->handle($service);
        (new \App\Jobs\SendWhatsAppNotificationJob('912345678', 'hola'))->handle($service);
    }

    public function test_closed_teacher_receives_no_welcome_bonus_even_if_verification_completes_late(): void
    {
        [$user, $teacher] = $this->teacher();
        $this->legacyClosed($user);
        $method = new \ReflectionMethod(\App\Http\Controllers\Auth\PhoneVerificationController::class, 'grantTeacherWelcomeBonus');
        $method->setAccessible(true);
        $method->invoke(new \App\Http\Controllers\Auth\PhoneVerificationController(), $user->fresh());
        $this->assertSame(10, $teacher->fresh()->credits_available);
        $this->assertSame(0, CreditTransaction::where('teacher_profile_id', $teacher->id)->count());
    }

    public function test_guest_receipt_expires_with_the_session_reference_window(): void
    {
        $user = $this->user();
        $this->actingAs($user)->delete('/profile', $this->closure())->assertRedirect(route('account.closure.status'));
        $this->get(route('account.closure.status'))->assertOk();
        $this->travel(31)->minutes();
        $this->get(route('account.closure.status'))->assertNotFound();
    }

    public function test_closure_proof_is_never_written_to_logs_or_cache_in_clear_text(): void
    {
        $path = storage_path('framework/testing/closure-proof.log');
        @unlink($path);
        config(['logging.default' => 'closure_proof', 'logging.channels.closure_proof' => ['driver' => 'single', 'path' => $path, 'level' => 'debug'], 'mail.default' => 'array', 'mail.allowlist' => '']);
        Notification::fake();
        $user = $this->user();
        $code = $this->issueClosureCode($user);
        $entry = Cache::get('account-closure:'.$user->id.':'.hash('sha256', session('account_closure_nonce')));
        $this->assertNotSame($code, $entry['hash']);
        $this->assertStringNotContainsString($code, json_encode($entry));
        $this->delete('/profile', $this->closure(['password' => '', 'code' => $code]))->assertRedirect(route('account.closure.status'));
        $this->assertStringNotContainsString($code, is_file($path) ? (string) file_get_contents($path) : '');
        @unlink($path);
    }

    /** Simula una cuenta cerrada con el flujo anterior: acceso restringido, solicitud pendiente, nada suprimido. */
    public function legacyClosed(User $user): AccountDeletionRequest
    {
        $user->forceFill(['account_closed_at' => now()])->save();

        return AccountDeletionRequest::create(['reference' => (string) Str::uuid(), 'user_id' => $user->id, 'identity_method' => 'password', 'snapshot' => app(AccountLifecycleService::class)->summary($user->fresh()), 'events' => [['action' => 'requested', 'at' => now()->toIso8601String(), 'actor_id' => $user->id]]]);
    }

    public function test_legacy_pending_request_is_completed_by_the_owner_without_support_and_without_reviving_access(): void
    {
        [$teacherUser, $teacher] = $this->teacher(); $parent = $this->user();
        $lesson = $this->scheduledLesson($parent, $teacher);
        $deletion = $this->legacyClosed($parent);
        // Still restricted, but the owner can reconfirm identity and complete the erasure by themselves.
        $this->actingAs($parent->fresh())->get('/dashboard')->assertRedirect(route('account.closure.status'));
        $this->delete('/profile', $this->closure(['password' => 'wrong']))->assertSessionHasErrors('password');
        $this->assertNull($parent->fresh()->account_erased_at);
        $this->delete('/profile', $this->closure())->assertRedirect(route('account.closure.status'));
        $this->assertSame('completed', $deletion->fresh()->status);
        $this->assertNotNull($parent->fresh()->account_erased_at);
        $this->assertSame('cancelled', $lesson->fresh()->status);
        $this->assertSame(10, $teacher->fresh()->credits_available);
        $this->assertSame(['requested', 'reconfirmed', 'erased'], array_column($deletion->fresh()->events, 'action'));
        $this->assertDatabaseCount('account_deletion_requests', 1);
    }


    public function test_teacher_closure_cancels_classes_republishes_general_requests_and_asks_parent_for_directed_ones(): void
    {
        Notification::fake();
        [$teacherUser, $teacher] = $this->teacher(); [, $other] = $this->teacher();
        $parent = $this->user();
        $general = $this->scheduledLesson($parent, $teacher, 2);
        $generalRequest = $general->classRequest;
        $directedRequest = $this->classRequest($parent, $teacher);
        $directedRequest->teacher_profile_id = $teacher->id; $directedRequest->save();
        $directedLesson = app(LessonSchedulingService::class)->schedule($directedRequest->id, $teacher->id, now()->addDays(3)->toIso8601String(), 60, fn () => null);
        $this->actingAs($teacherUser)->delete('/profile', $this->closure(['accept_credit_loss' => true]))->assertRedirect();

        $this->assertSame('cancelled', $general->fresh()->status);
        $this->assertSame('cancelled', $directedLesson->fresh()->status);
        $this->assertSame('open', $generalRequest->fresh()->status);
        $this->assertNotNull($generalRequest->fresh()->republished_at);
        $this->assertSame($generalRequest->help_needed, ClassRequest::find($generalRequest->id)->help_needed);
        $this->assertSame('expired', $directedRequest->fresh()->status);
        $this->assertSame(2, Lesson::where('class_request_id', $generalRequest->id)->count() + Lesson::where('class_request_id', $directedRequest->id)->count());
        $this->assertSame(1, ClassRequest::where('student_id', $generalRequest->student_id)->where('status', 'open')->count());
        Notification::assertSentTo($parent, \App\Notifications\ClassCancelledNotification::class);
        Notification::assertSentTo($parent, \App\Notifications\TeacherClosedAccountNotification::class, fn ($n) => $n->republished === true);
        Notification::assertSentTo($parent, \App\Notifications\TeacherClosedAccountNotification::class, fn ($n) => $n->republished === false);
        $this->assertSame(10, (int) $other->fresh()->credits_available);
    }

    public function test_republished_request_is_not_expired_by_its_original_age_and_is_visible_to_other_teachers(): void
    {
        [$teacherUser, $teacher] = $this->teacher(); [$otherUser, $other] = $this->teacher(); $parent = $this->user();
        $lesson = $this->scheduledLesson($parent, $teacher);
        $request = $lesson->classRequest;
        $other->subjects()->sync([$request->subject_id]);
        ClassRequest::whereKey($request->id)->update(['created_at' => now()->subDays(3)]);
        $this->actingAs($teacherUser)->delete('/profile', $this->closure(['accept_credit_loss' => true]))->assertRedirect();
        $this->assertSame(1, ClassRequest::visibleToTeacher($other->id, [], [$request->subject_id])->count());
        \Illuminate\Support\Facades\Artisan::call('mova:expire-class-requests');
        $this->assertSame('open', $request->fresh()->status);
    }

    public function test_parent_closure_cancels_future_classes_notifies_teacher_and_never_republishes(): void
    {
        Notification::fake();
        [$teacherUser, $teacher] = $this->teacher(); $parent = $this->user();
        $lesson = $this->scheduledLesson($parent, $teacher);
        $pending = $this->classRequest($parent, $teacher);
        $this->actingAs($parent)->delete('/profile', $this->closure())->assertRedirect();
        $this->assertSame('cancelled', $lesson->fresh()->status);
        $this->assertSame(10, $teacher->fresh()->credits_available);
        $this->assertSame(0, $teacher->fresh()->credits_reserved);
        $this->assertSame('expired', $lesson->classRequest->fresh()->status);
        $this->assertSame('expired', $pending->fresh()->status);
        $this->assertSame(0, ClassRequest::where('status', 'open')->count());
        $this->assertSame('[Eliminado por el titular]', $pending->fresh()->help_needed);
        Notification::assertSentTo($teacherUser, \App\Notifications\ClassCancelledNotification::class);
        Notification::assertNotSentTo($parent, \App\Notifications\ClassCancelledNotification::class);
    }

    public function test_finished_classes_and_reports_are_preserved(): void
    {
        [$teacherUser, $teacher] = $this->teacher(); $parent = $this->user();
        $request = $this->classRequest($parent, $teacher);
        $done = Lesson::create(['teacher_profile_id' => $teacher->id, 'student_id' => $request->student_id, 'class_request_id' => $request->id, 'start_time' => now()->subDays(2), 'duration_minutes' => 60, 'price_frozen_pen' => 20, 'status' => 'completed']);
        $this->actingAs($parent)->delete('/profile', $this->closure())->assertRedirect();
        $done = $done->fresh();
        $this->assertSame('completed', $done->status);
        $this->assertNull($done->cancelled_at);
        $this->assertSame($request->student_id, $done->student_id);
    }

    public function test_email_and_phone_are_released_and_a_new_registration_starts_from_zero(): void
    {
        [$user, $teacher] = $this->teacher(); $email = $user->email;
        $user->forceFill(['phone' => '987654321', 'phone_verified_normalized' => '+51987654321', 'phone_verified_at' => now()])->save();
        $this->actingAs($user)->delete('/profile', $this->closure(['accept_credit_loss' => true]))->assertRedirect();
        $this->assertGuest();
        // The same email may register again, now as a parent, with a brand-new identity.
        $this->post('/register', ['name' => 'Nueva Familia', 'email' => $email, 'password' => 'Str0ng-Pass-2026!', 'password_confirmation' => 'Str0ng-Pass-2026!', 'role' => 'parent', 'accepted_terms' => true]);
        $fresh = User::where('email', $email)->first();
        $this->assertNotNull($fresh);
        $this->assertNotSame($user->id, $fresh->id);
        $this->assertNull($fresh->account_closed_at);
        $this->assertNull($fresh->teacherProfile);
        $this->assertFalse($fresh->hasRole('teacher'));
        $this->assertSame(0, $fresh->students()->count());
        // The phone number is free for another account's verification as well.
        $other = $this->user();
        $other->forceFill(['phone' => '987654321', 'phone_verified_normalized' => '+51987654321', 'phone_verified_at' => now()])->save();
        $this->assertSame(1, User::where('phone_verified_normalized', '+51987654321')->count());
    }


    public function test_cleanup_runs_after_commit_and_the_finisher_retries_failures_and_flags_late_balance(): void
    {
        [$user, $teacher] = $this->teacher();
        $user->forceFill(['avatar_url' => 'https://example.test/a.png'])->save();
        $recharge = RechargeRequest::create(['teacher_profile_id' => $teacher->id, 'package_code' => 'inicio', 'package_name' => 'Inicio', 'credits' => 5, 'amount_pen' => 10, 'payment_method' => 'yape', 'status' => 'pending']);
        $this->actingAs($user)->delete('/profile', $this->closure(['accept_credit_loss' => true]))->assertRedirect();
        $deletion = AccountDeletionRequest::first();
        $this->assertSame('completed', $deletion->cleanup_status);
        $this->assertNull($user->fresh()->avatar_url);

        // A late provider confirmation after closure credits the (unusable) wallet through the normal service...
        app(\App\Services\RechargeApprovalService::class)->credit($recharge, $this->user('admin')->id);
        $this->assertSame(5, $teacher->fresh()->credits_available);
        // ...and a failed cleanup is retried by the scheduled command, which also flags that balance as an incident.
        $deletion->forceFill(['cleanup_status' => 'failed'])->save();
        \Illuminate\Support\Facades\Artisan::call('mova:account-closure:finish');
        $this->assertSame('completed', $deletion->fresh()->cleanup_status);
        $this->assertDatabaseHas('operational_alerts', ['alert_key' => "account-closure:{$user->id}:balance-after-closure"]);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_two_simultaneous_requests_produce_one_closure_one_forfeiture_and_one_cancellation_each(): void
    {
        [$user, $teacher] = $this->teacher(); $parent = $this->user();
        $lesson = $this->scheduledLesson($parent, $teacher);
        $service = app(AccountLifecycleService::class);
        $first = $service->closeAccount($user, 'password', null, true);
        $second = $service->closeAccount($user->fresh(), 'password', null, true);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CreditTransaction::where('type', 'forfeiture')->count());
        $this->assertSame(1, CreditTransaction::where('lesson_id', $lesson->id)->where('type', 'refund')->count());
        $this->assertCount(2, $first->fresh()->events);
        $this->assertSame(0, $teacher->fresh()->credits_available);
    }

    public function test_admin_cannot_use_automatic_closure_and_keeps_the_only_admin_access(): void
    {
        $admin = $this->user('admin'); $admin->assignRole('teacher');
        $this->actingAs($admin)->delete('/profile', $this->closure(['accept_credit_loss' => true]))->assertForbidden();
        $this->assertNull($admin->fresh()->account_erased_at);
        $this->assertTrue($admin->fresh()->hasRole('admin'));
        $this->assertDatabaseCount('account_deletion_requests', 0);
    }


    public function test_only_future_classes_are_cancelled_and_a_class_already_given_follows_the_normal_flow(): void
    {
        [$teacherUser, $teacher] = $this->teacher(); $parent = $this->user();
        $lesson = $this->scheduledLesson($parent, $teacher);
        // The class already took place but is still `scheduled` until the parent confirms or the scheduler settles it.
        Lesson::whereKey($lesson->id)->update(['start_time' => now()->subHours(3)]);
        RechargeRequest::create(['teacher_profile_id' => $teacher->id, 'package_code' => 'inicio', 'package_name' => 'Inicio', 'credits' => 4, 'amount_pen' => 8, 'payment_method' => 'yape', 'status' => 'approved', 'approved_at' => now()->subDay()]);
        $this->actingAs($teacherUser)->delete('/profile', $this->closure(['accept_credit_loss' => true, 'credit_loss_amount' => 5]))->assertRedirect();
        $lesson = $lesson->fresh();
        $this->assertSame('scheduled', $lesson->status);
        $this->assertNull($lesson->cancelled_at);
        $this->assertSame(0, CreditTransaction::where('lesson_id', $lesson->id)->where('type', 'refund')->count());
        $this->assertSame(1, $teacher->fresh()->credits_reserved);
        $this->assertSame(0, AccountDeletionRequest::first()->outcome['classes_cancelled']);
    }

    public function test_suspended_or_indebted_accounts_can_close_but_cannot_register_again(): void
    {
        [$user, $teacher] = $this->teacher(); $email = $user->email;
        $user->forceFill(['phone' => '987654321', 'phone_verified_normalized' => '+51987654321', 'suspended_at' => now(), 'suspension_reason' => 'QA'])->save();
        $this->actingAs($user)->delete('/profile', $this->closure(['accept_credit_loss' => true]))->assertRedirect(route('account.closure.status'));
        $this->assertNotNull($user->fresh()->account_erased_at);
        $this->assertTrue(AccountDeletionRequest::first()->outcome['registration_blocked']);
        // Only hashes are stored, never the email or the phone.
        $stored = json_encode(DB::table('closure_blocklist')->get());
        $this->assertStringNotContainsString($email, $stored);
        $this->assertStringNotContainsString('987654321', $stored);
        $this->assertSame(2, DB::table('closure_blocklist')->count());

        $payload = ['name' => 'Nueva', 'email' => $email, 'password' => 'Str0ng-Pass-2026!', 'password_confirmation' => 'Str0ng-Pass-2026!', 'role' => 'parent', 'accepted_terms' => true];
        $this->post('/register', $payload)->assertSessionHasErrors('email');
        $this->post('/register', ['email' => 'otro-'.Str::random(6).'@example.test', 'phone' => '987654321'] + $payload)->assertSessionHasErrors('email');
        $this->assertSame(0, User::where('email', $email)->count());
        // The same email in another casing is blocked too; an unrelated person is not.
        $this->post('/register', ['email' => strtoupper($email)] + $payload)->assertSessionHasErrors('email');
        $this->post('/register', ['email' => 'limpio-'.Str::random(6).'@example.test'] + $payload)->assertSessionHasNoErrors();
    }

    public function test_an_account_with_debt_closes_and_is_blocklisted_but_a_normal_closure_is_not(): void
    {
        [$user, $teacher] = $this->teacher();
        $teacher->update(['credits_available' => -3]);
        $this->actingAs($user)->delete('/profile', $this->closure())->assertRedirect(route('account.closure.status'));
        $this->assertSame(-3, $teacher->fresh()->credits_available);
        $this->assertSame(0, CreditTransaction::where('type', 'forfeiture')->count());
        $this->assertGreaterThan(0, DB::table('closure_blocklist')->where('reason', 'debt')->count());

        $normal = $this->user();
        $count = DB::table('closure_blocklist')->count();
        $this->actingAs($normal)->delete('/profile', $this->closure())->assertRedirect();
        $this->assertSame($count, DB::table('closure_blocklist')->count());
    }

    public function test_the_credit_loss_cannot_exceed_the_amount_the_user_accepted(): void
    {
        [$user, $teacher] = $this->teacher();
        $this->actingAs($user)->delete('/profile', $this->closure(['accept_credit_loss' => true, 'credit_loss_amount' => 4]))->assertSessionHasErrors('accept_credit_loss');
        $this->assertNull($user->fresh()->account_erased_at);
        $this->assertSame(10, $teacher->fresh()->credits_available);
        $this->assertSame(0, CreditTransaction::where('type', 'forfeiture')->count());
        $this->delete('/profile', $this->closure(['accept_credit_loss' => true, 'credit_loss_amount' => 10]))->assertRedirect();
        $this->assertSame(0, $teacher->fresh()->credits_available);
    }

    public function test_requests_waiting_for_parental_approval_keep_their_state_when_a_teacher_closes(): void
    {
        [$teacherUser, $teacher] = $this->teacher(); $parent = $this->user();
        $request = $this->classRequest($parent, $teacher);
        $request->update(['status' => 'pending_parent_approval']);
        $this->actingAs($teacherUser)->delete('/profile', $this->closure(['accept_credit_loss' => true, 'credit_loss_amount' => 10]))->assertRedirect();
        $this->assertSame('pending_parent_approval', $request->fresh()->status);
    }

}
