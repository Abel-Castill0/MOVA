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
        $this->get('/dashboard')->assertRedirect(route('verification.notice'));
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

    public function test_empty_account_is_closed_without_unapproved_purge_and_receives_receipt(): void
    {
        $user = $this->user();
        $this->actingAs($user)->delete('/profile', $this->closure())->assertRedirect(route('account.closure.status'));
        $this->assertGuest();
        $this->assertNotNull($user->fresh()->account_closed_at);
        $this->get(route('account.closure.status'))->assertInertia(fn ($page) => $page->where('receipt.status', 'pending_review'));
        $this->assertDatabaseCount('account_deletion_requests', 1);
    }

    public function test_retry_is_idempotent_and_other_user_id_is_ignored(): void
    {
        $user = $this->user(); $other = $this->user();
        $this->actingAs($user)->delete('/profile', $this->closure(['user_id' => $other->id]))->assertRedirect();
        $first = AccountDeletionRequest::first();
        $again = app(AccountLifecycleService::class)->requestClosure($user->fresh(), 'password');
        $this->assertSame($first->id, $again->id);
        $this->assertNull($other->fresh()->account_closed_at);
        $this->assertDatabaseCount('account_deletion_requests', 1);
        $this->assertCount(1, $again->events);
    }

    public function test_admin_even_with_teacher_role_cannot_use_closure(): void
    {
        $admin = $this->user('admin'); $admin->assignRole('teacher');
        $this->actingAs($admin)->delete('/profile', $this->closure())->assertForbidden();
        $this->assertNull($admin->fresh()->account_closed_at);
    }

    public function test_multiple_roles_preserve_children_classes_credits_and_payments(): void
    {
        [$user, $teacher] = $this->teacher(); $user->assignRole('parent');
        $request = $this->classRequest($user, $teacher);
        $studentBefore = $request->student->getAttributes();
        Lesson::create(['teacher_profile_id' => $teacher->id, 'student_id' => $request->student_id, 'class_request_id' => $request->id, 'start_time' => now()->addDay(), 'duration_minutes' => 60, 'price_frozen_pen' => 20, 'status' => 'scheduled']);
        $transaction = CreditTransaction::create(['teacher_profile_id' => $teacher->id, 'idempotency_key' => 'qa-deposit', 'type' => 'deposit', 'amount' => 10, 'description' => 'QA']);
        $recharge = RechargeRequest::create(['teacher_profile_id' => $teacher->id, 'package_code' => 'inicio', 'package_name' => 'Inicio', 'credits' => 5, 'amount_pen' => 10, 'payment_method' => 'culqi', 'status' => 'pending']);
        $order = PaymentOrder::create(['recharge_request_id' => $recharge->id, 'attempt_number' => 1, 'idempotency_key' => (string) Str::uuid(), 'provider' => 'culqi', 'status' => 'pending', 'amount_minor' => 1000, 'currency' => 'PEN', 'review_reason' => 'qa_review']);
        $ledgerBefore = $transaction->fresh()->getAttributes();
        $this->actingAs($user)->delete('/profile', $this->closure())->assertRedirect();
        $summary = AccountDeletionRequest::first()->snapshot;
        $this->assertSame(1, $summary['students']);
        $this->assertSame(1, $summary['pending_classes']);
        $this->assertSame(1, $summary['pending_payments']);
        $this->assertSame(1, $summary['payment_reviews']);
        $this->assertEqualsCanonicalizing(['parent', 'teacher'], $summary['roles']);
        $this->assertSame(10, $teacher->fresh()->credits_available);
        $this->assertSame($ledgerBefore, $transaction->fresh()->getAttributes());
        $this->assertSame($studentBefore, $request->student->fresh()->getAttributes());
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertDatabaseCount('classes', 1);
    }

    public function test_sessions_tokens_and_routine_notifications_are_revoked(): void
    {
        $user = $this->user();
        $user->forceFill(['whatsapp_opt_in_at' => now()])->save();
        $user->createToken('qa-session');
        DB::table('sessions')->insert(['id' => 'qa-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        app(AccountLifecycleService::class)->requestClosure($user, 'password');
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertFalse($user->fresh()->wantsWhatsAppNotifications());
        $event = new NotificationSending($user, new AccountClosureCode('123456'), 'mail');
        $this->assertFalse(app(PreventClosedAccountNotifications::class)->handle($event));
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
        app(AccountLifecycleService::class)->requestClosure($parent, 'password');
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

    public function test_closed_teacher_cannot_be_reverified_or_exposed_even_if_flag_is_restored(): void
    {
        [$user, $profile] = $this->teacher();
        app(AccountLifecycleService::class)->requestClosure($user, 'password');
        $this->actingAs($this->user('admin'))->post(route('admin.teachers.verify', $profile))->assertStatus(409);
        $profile->update(['is_verified' => true]);
        $this->get(route('teachers.show', $profile))->assertNotFound();
    }

    public function test_status_is_private_and_admin_response_refreshes_anonymous_receipt(): void
    {
        $user = $this->user(); $admin = $this->user('admin');
        $this->actingAs($user)->delete('/profile', $this->closure());
        $deletion = AccountDeletionRequest::first();
        app(AccountLifecycleService::class)->respond($deletion, $admin, 'Revisamos las obligaciones pendientes. Contacta con soporte para coordinar.');
        $this->get('/cuenta/solicitud')->assertInertia(fn ($page) => $page->where('receipt.status', 'responded'));
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
            public function requestClosure(User $user, string $identityMethod, ?\Closure $validateIdentity = null): AccountDeletionRequest
            {
                User::whereKey($user->id)->update(['email' => 'changed-before-lock@example.test']);

                return parent::requestClosure($user, $identityMethod, $validateIdentity);
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
            public function requestClosure(User $user, string $identityMethod, ?\Closure $validateIdentity = null): AccountDeletionRequest
            {
                User::whereKey($user->id)->update(['password' => Hash::make('RotatedPass123!')]);

                return parent::requestClosure($user, $identityMethod, $validateIdentity);
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
        $this->actingAs($user)->delete('/profile', $this->closure())->assertRedirect();
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
}
