<?php

namespace Tests\Feature;

use App\Models\{AccountDeletionRequest, ClassRequest, ClosureBlocklist, CreditTransaction, PaymentOrder, RechargeRequest, Student, Subject, TeacherProfile, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Log, Notification, Hash};
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Eliminación segura de cuentas por un administrador (Admin/Users): reutiliza AccountLifecycleService, exige
 * motivo y confirmación, no destruye saldo con derecho a devolución ni pagos sin resolver, deja auditoría y
 * libera el correo sin heredar nada — salvo cuentas suspendidas o con deuda, que quedan bloqueadas.
 */
class AdminUserErasureTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'Solicitud verificada por soporte del titular';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Notification::fake();
    }

    private function user(string $role, array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $user->assignRole($role);

        return $user;
    }

    private function teacher(int $credits = 0, array $attrs = []): array
    {
        $user = $this->user('teacher', $attrs);
        $profile = TeacherProfile::create(['user_id' => $user->id, 'is_verified' => true, 'hourly_rate' => 20, 'credits_available' => $credits, 'credits_reserved' => 0]);
        if ($credits > 0) {
            CreditTransaction::create(['teacher_profile_id' => $profile->id, 'idempotency_key' => 'seed-'.$profile->id, 'type' => 'deposit', 'amount' => $credits, 'description' => 'QA']);
        }

        return [$user, $profile];
    }

    private function payload(User $target, array $extra = []): array
    {
        return array_replace(['reason' => self::REASON, 'confirm_email' => $target->email], $extra);
    }

    public function test_admin_erases_a_parent_anonymizes_data_and_frees_the_email_for_a_clean_new_account(): void
    {
        $admin = $this->user('admin');
        $parent = $this->user('parent', ['name' => 'Madre Real', 'email' => 'madre.real@example.com', 'phone' => '987654321']);
        $student = Student::create(['parent_user_id' => $parent->id, 'first_name' => 'Menor', 'last_name' => 'Privado', 'grade_level' => 'primaria']);
        $subject = Subject::firstOrCreate(['name' => 'Matemáticas'], ['level' => 'Primaria']);
        $request = ClassRequest::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'duration_minutes' => 60, 'help_needed' => 'Texto sensible', 'status' => 'open']);

        $this->actingAs($admin)->post(route('admin.users.erase', $parent), $this->payload($parent))
            ->assertRedirect()->assertSessionHas('success');

        $fresh = $parent->fresh();
        $this->assertNotNull($fresh->account_closed_at);
        $this->assertNotNull($fresh->account_erased_at);
        $this->assertSame('Cuenta eliminada', $fresh->name);
        $this->assertNull($fresh->phone);
        $this->assertStringEndsWith('@mova.invalid', $fresh->email);
        $this->assertSame([], $fresh->getRoleNames()->all());
        $this->assertSame('Estudiante', Student::withTrashed()->find($student->id)->first_name);
        $this->assertSame('expired', $request->fresh()->status);

        // El correo queda libre: un alta nueva con él no hereda datos, roles, créditos ni clases.
        $reborn = User::factory()->create(['email' => 'madre.real@example.com']);
        $this->assertNotSame($parent->id, $reborn->id);
        $this->assertSame([], $reborn->getRoleNames()->all());
        $this->assertSame(0, $reborn->students()->count());
        $this->assertFalse(ClosureBlocklist::blocks('madre.real@example.com', '987654321'));
    }

    public function test_audit_trail_records_who_why_and_that_it_was_done_by_an_admin(): void
    {
        Log::spy();
        $admin = $this->user('admin');
        $parent = $this->user('parent');

        $this->actingAs($admin)->post(route('admin.users.erase', $parent), $this->payload($parent))->assertSessionHasNoErrors();

        $record = AccountDeletionRequest::where('user_id', $parent->id)->firstOrFail();
        $this->assertSame('completed', $record->status);
        $this->assertSame('admin', $record->identity_method);
        $requested = collect($record->events)->firstWhere('action', 'requested');
        $this->assertSame($admin->id, $requested['actor_id']);
        $this->assertTrue($requested['by_admin']);
        $this->assertSame(self::REASON, $requested['reason']);
        Log::shouldHaveReceived('info')->withArgs(fn ($m, $c = []) => $m === 'ADMIN_USER_ERASED' && $c['admin_id'] === $admin->id && $c['user_id'] === $parent->id && ! array_key_exists('email', $c))->once();
    }

    public function test_wrong_confirmation_email_or_missing_reason_changes_nothing(): void
    {
        $admin = $this->user('admin');
        $parent = $this->user('parent');

        $this->actingAs($admin)->post(route('admin.users.erase', $parent), $this->payload($parent, ['confirm_email' => 'otro@example.com']))
            ->assertSessionHasErrors('confirm_email');
        $this->post(route('admin.users.erase', $parent), $this->payload($parent, ['reason' => 'corto']))->assertSessionHasErrors('reason');
        $this->post(route('admin.users.erase', $parent), ['confirm_email' => $parent->email])->assertSessionHasErrors('reason');

        $this->assertNull($parent->fresh()->account_closed_at);
        $this->assertSame(0, AccountDeletionRequest::count());
    }

    public function test_confirmation_email_comparison_ignores_case_and_surrounding_spaces(): void
    {
        $admin = $this->user('admin');
        $parent = $this->user('parent', ['email' => 'Familia.Mixta@Example.com']);

        $this->actingAs($admin)->post(route('admin.users.erase', $parent), $this->payload($parent, ['confirm_email' => '  familia.mixta@example.COM ']))
            ->assertSessionHasNoErrors();

        $this->assertNotNull($parent->fresh()->account_erased_at);
    }

    public function test_admins_cannot_be_erased_neither_other_admins_nor_oneself(): void
    {
        $admin = $this->user('admin');
        $other = $this->user('admin');

        $this->actingAs($admin)->post(route('admin.users.erase', $other), $this->payload($other))->assertRedirect()->assertSessionHas('error');
        $this->post(route('admin.users.erase', $admin), $this->payload($admin))->assertRedirect()->assertSessionHas('error');

        $this->assertNull($other->fresh()->account_closed_at);
        $this->assertNull($admin->fresh()->account_closed_at);
        $this->assertTrue($other->fresh()->hasRole('admin'));
    }

    public function test_only_admins_can_use_the_erase_and_preview_endpoints(): void
    {
        $parent = $this->user('parent');
        $victim = $this->user('parent');
        $teacher = $this->user('teacher');

        $this->actingAs($parent)->post(route('admin.users.erase', $victim), $this->payload($victim))->assertForbidden();
        $this->actingAs($teacher)->get(route('admin.users.erasure-preview', $victim))->assertForbidden();
        auth()->logout();
        $this->post(route('admin.users.erase', $victim), $this->payload($victim))->assertRedirect(route('login'));

        $this->assertNull($victim->fresh()->account_closed_at);
    }

    public function test_credits_inside_the_refund_window_block_the_erasure_and_are_never_destroyed(): void
    {
        $admin = $this->user('admin');
        [$teacherUser, $profile] = $this->teacher(4);
        RechargeRequest::create(['teacher_profile_id' => $profile->id, 'package_code' => 'inicio', 'package_name' => 'Inicio', 'credits' => 4, 'amount_pen' => 8, 'payment_method' => 'yape', 'status' => 'approved', 'approved_at' => now()->subDay()]);

        $this->actingAs($admin)->get(route('admin.users.erasure-preview', $teacherUser))
            ->assertOk()->assertJsonPath('summary.credits_refundable', 4)->assertJsonCount(1, 'blockers');
        $this->post(route('admin.users.erase', $teacherUser), $this->payload($teacherUser, ['accept_credit_loss' => 4]))
            ->assertSessionHasErrors('erase');

        $this->assertNull($teacherUser->fresh()->account_closed_at);
        $this->assertSame(4, $profile->fresh()->credits_available);
        $this->assertSame(0, CreditTransaction::where('type', 'forfeiture')->count());
    }

    public function test_pending_or_reviewed_payments_block_the_erasure(): void
    {
        $admin = $this->user('admin');
        [$teacherUser, $profile] = $this->teacher(0);
        $recharge = RechargeRequest::create(['teacher_profile_id' => $profile->id, 'package_code' => 'inicio', 'package_name' => 'Inicio', 'credits' => 5, 'amount_pen' => 10, 'payment_method' => 'culqi', 'status' => 'pending']);
        PaymentOrder::create(['recharge_request_id' => $recharge->id, 'attempt_number' => 1, 'idempotency_key' => (string) Str::uuid(), 'provider' => 'culqi', 'status' => 'pending', 'amount_minor' => 1000, 'currency' => 'PEN']);

        $this->actingAs($admin)->post(route('admin.users.erase', $teacherUser), $this->payload($teacherUser))->assertSessionHasErrors('erase');
        $this->assertNull($teacherUser->fresh()->account_closed_at);
    }

    public function test_unused_credits_outside_the_window_need_explicit_confirmation_and_leave_a_ledger_entry(): void
    {
        $admin = $this->user('admin');
        [$teacherUser, $profile] = $this->teacher(6);

        $this->actingAs($admin)->post(route('admin.users.erase', $teacherUser), $this->payload($teacherUser))->assertSessionHasErrors('accept_credit_loss');
        $this->assertSame(6, $profile->fresh()->credits_available);

        // Una cifra menor que el saldo real (la vista previa quedó desfasada) tampoco basta.
        $this->post(route('admin.users.erase', $teacherUser), $this->payload($teacherUser, ['accept_credit_loss' => 2]))->assertSessionHasErrors('accept_credit_loss');
        $this->assertSame(6, $profile->fresh()->credits_available);

        $this->post(route('admin.users.erase', $teacherUser), $this->payload($teacherUser, ['accept_credit_loss' => 6]))->assertSessionHasNoErrors();
        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertSame(-6, (int) CreditTransaction::where('type', 'forfeiture')->value('amount'));
        $this->assertSame(['deposit', 'forfeiture'], CreditTransaction::where('teacher_profile_id', $profile->id)->pluck('type')->sort()->values()->all());
        $this->assertFalse((bool) $profile->fresh()->is_verified);
    }

    public function test_a_suspended_account_cannot_evade_the_sanction_by_being_erased_and_re_registered(): void
    {
        $admin = $this->user('admin');
        $banned = $this->user('parent', ['email' => 'sancionado@example.com', 'phone' => '911222333', 'suspended_at' => now(), 'suspension_reason' => 'Conducta']);
        $clean = $this->user('parent', ['email' => 'limpio@example.com']);

        $this->actingAs($admin)->post(route('admin.users.erase', $banned), $this->payload($banned))->assertSessionHasNoErrors();
        $this->post(route('admin.users.erase', $clean), $this->payload($clean))->assertSessionHasNoErrors();

        $this->assertTrue(ClosureBlocklist::blocks('Sancionado@Example.com'));
        $this->assertTrue(ClosureBlocklist::blocks(null, '911222333'));
        $this->assertFalse(ClosureBlocklist::blocks('limpio@example.com'));
        $this->assertSame(0, ClosureBlocklist::where('hash', 'sancionado@example.com')->count(), 'Solo se guardan hashes, nunca el correo.');
    }

    public function test_an_account_in_debt_is_blocklisted_when_erased(): void
    {
        $admin = $this->user('admin');
        [$teacherUser, $profile] = $this->teacher(0, ['email' => 'deudor@example.com']);
        $profile->update(['credits_available' => -3]);

        $this->actingAs($admin)->post(route('admin.users.erase', $teacherUser), $this->payload($teacherUser))->assertSessionHasNoErrors();

        $this->assertTrue(ClosureBlocklist::blocks('deudor@example.com'));
    }

    public function test_double_submit_is_idempotent_and_cannot_forfeit_twice(): void
    {
        $admin = $this->user('admin');
        [$teacherUser, $profile] = $this->teacher(3);
        $email = $teacherUser->email;
        $payload = $this->payload($teacherUser, ['accept_credit_loss' => 3]);

        $this->actingAs($admin)->post(route('admin.users.erase', $teacherUser), $payload)->assertSessionHasNoErrors();
        $this->post(route('admin.users.erase', $teacherUser), $payload)->assertRedirect()->assertSessionHas('error');

        $this->assertSame(1, CreditTransaction::where('type', 'forfeiture')->count());
        $this->assertSame(1, AccountDeletionRequest::where('user_id', $teacherUser->id)->count());
        $this->assertNotSame($email, $teacherUser->fresh()->email);
    }

    public function test_suspension_and_reactivation_are_audited_and_refuse_closed_accounts(): void
    {
        Log::spy();
        $admin = $this->user('admin');
        $parent = $this->user('parent');

        $this->actingAs($admin)->post(route('admin.users.suspend', $parent), ['reason' => 'Motivo de prueba'])->assertSessionHas('success');
        Log::shouldHaveReceived('info')->withArgs(fn ($m, $c = []) => $m === 'ADMIN_USER_SUSPENDED' && $c['user_id'] === $parent->id)->once();
        $this->post(route('admin.users.unsuspend', $parent))->assertSessionHas('success');
        Log::shouldHaveReceived('info')->withArgs(fn ($m, $c = []) => $m === 'ADMIN_USER_UNSUSPENDED' && $c['user_id'] === $parent->id)->once();

        $this->post(route('admin.users.erase', $parent), $this->payload($parent))->assertSessionHasNoErrors();
        $this->post(route('admin.users.suspend', $parent))->assertSessionHas('error');
        $this->post(route('admin.users.unsuspend', $parent))->assertSessionHas('error');
        $this->assertNull($parent->fresh()->suspended_at);
    }

    public function test_free_text_reason_is_redacted_before_it_is_kept_forever(): void
    {
        $admin = $this->user('admin');
        $parent = $this->user('parent');

        $this->actingAs($admin)->post(route('admin.users.erase', $parent), $this->payload($parent, ['reason' => 'Lo pidió por WhatsApp desde el 987654321 y correo madre@example.com']))
            ->assertSessionHasNoErrors();

        $stored = json_encode(AccountDeletionRequest::where('user_id', $parent->id)->value('events'));
        $this->assertStringNotContainsString('987654321', $stored);
        $this->assertStringNotContainsString('madre@example.com', $stored);
    }

    public function test_a_pending_manual_recharge_blocks_the_erasure_but_a_resolved_review_does_not(): void
    {
        $admin = $this->user('admin');
        [$blockedUser, $blockedProfile] = $this->teacher(0);
        RechargeRequest::create(['teacher_profile_id' => $blockedProfile->id, 'package_code' => 'inicio', 'package_name' => 'Inicio', 'credits' => 5, 'amount_pen' => 10, 'payment_method' => 'yape', 'status' => 'pending']);

        [$freeUser, $freeProfile] = $this->teacher(0);
        $paid = RechargeRequest::create(['teacher_profile_id' => $freeProfile->id, 'package_code' => 'inicio', 'package_name' => 'Inicio', 'credits' => 5, 'amount_pen' => 10, 'payment_method' => 'culqi', 'status' => 'rejected']);
        PaymentOrder::create(['recharge_request_id' => $paid->id, 'attempt_number' => 1, 'idempotency_key' => (string) Str::uuid(), 'provider' => 'culqi', 'status' => 'failed', 'amount_minor' => 1000, 'currency' => 'PEN', 'review_reason' => 'old_review', 'review_resolved_at' => now()->subDay()]);

        $this->actingAs($admin)->post(route('admin.users.erase', $blockedUser), $this->payload($blockedUser))->assertSessionHasErrors('erase');
        $this->post(route('admin.users.erase', $freeUser), $this->payload($freeUser))->assertSessionHasNoErrors();

        $this->assertNull($blockedUser->fresh()->account_closed_at);
        $this->assertNotNull($freeUser->fresh()->account_erased_at);
    }

    public function test_the_confirmed_credit_loss_must_match_the_real_balance_exactly(): void
    {
        $admin = $this->user('admin');
        [$teacherUser, $profile] = $this->teacher(6);

        $this->actingAs($admin)->post(route('admin.users.erase', $teacherUser), $this->payload($teacherUser, ['accept_credit_loss' => 999999]))
            ->assertSessionHasErrors('accept_credit_loss');

        $this->assertSame(6, $profile->fresh()->credits_available);
        $this->assertSame(0, CreditTransaction::where('type', 'forfeiture')->count());
    }

    public function test_gmail_aliases_cannot_dodge_the_blocklist(): void
    {
        $admin = $this->user('admin');
        $banned = $this->user('parent', ['email' => 'Ana.Perez@gmail.com', 'suspended_at' => now(), 'suspension_reason' => 'Conducta']);

        $this->actingAs($admin)->post(route('admin.users.erase', $banned), $this->payload($banned))->assertSessionHasNoErrors();

        foreach (['anaperez@gmail.com', 'ana.perez+nuevo@gmail.com', 'a.n.a.p.e.r.e.z@googlemail.com', 'ANA.PEREZ@GMAIL.COM'] as $alias) {
            $this->assertTrue(ClosureBlocklist::blocks($alias), $alias);
        }
        $this->assertFalse(ClosureBlocklist::blocks('ana.perez2@gmail.com'));
        $this->assertFalse(ClosureBlocklist::blocks('ana.perez@example.com'), 'Los puntos solo son equivalentes en Gmail.');
    }

    public function test_future_classes_are_cancelled_in_the_name_of_the_administration_not_of_the_erased_account(): void
    {
        $admin = $this->user('admin');
        [$teacherUser, $profile] = $this->teacher(5);
        $parent = $this->user('parent');
        $student = Student::create(['parent_user_id' => $parent->id, 'first_name' => 'Alumno', 'last_name' => 'QA', 'grade_level' => 'primaria']);
        $subject = Subject::firstOrCreate(['name' => 'Matemáticas'], ['level' => 'Primaria']);
        $profile->subjects()->sync([$subject->id]);
        $request = ClassRequest::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'duration_minutes' => 60, 'help_needed' => 'QA', 'status' => 'open']);
        $lesson = app(\App\Services\LessonSchedulingService::class)->schedule($request->id, $profile->id, now()->addDay()->toIso8601String(), 60, fn () => null);
        $this->assertSame(1, $profile->fresh()->credits_reserved);

        $this->actingAs($admin)->post(route('admin.users.erase', $parent), $this->payload($parent))->assertSessionHasNoErrors();

        $lesson = $lesson->fresh();
        $this->assertSame('cancelled', $lesson->status);
        $this->assertSame($admin->id, $lesson->cancelled_by);
        $this->assertSame('La administración de MOVA cerró la cuenta.', $lesson->cancel_reason);
        $this->assertSame(5, $profile->fresh()->credits_available, 'La reserva vuelve al profesor.');
        $this->assertSame(0, $profile->fresh()->credits_reserved);
    }

    public function test_user_list_hides_erased_accounts_by_default_and_searches_by_name_email_or_phone(): void
    {
        $admin = $this->user('admin');
        $this->user('parent', ['name' => 'Rosa Quispe', 'email' => 'rosa@example.com', 'phone' => '900111222']);
        $this->user('parent', ['name' => 'Luis Mamani', 'email' => 'luis@example.com']);
        $gone = $this->user('parent', ['name' => 'Eliminado Antes', 'email' => 'gone@example.com']);
        $this->actingAs($admin)->post(route('admin.users.erase', $gone), $this->payload($gone));

        $names = fn ($response) => collect($response->viewData('page')['props']['users']['data'])->pluck('name')->all();

        $default = $names($this->get(route('admin.users')));
        $this->assertContains('Rosa Quispe', $default);
        $this->assertNotContains('Cuenta eliminada', $default);
        $this->assertContains('Cuenta eliminada', $names($this->get(route('admin.users', ['erased' => 1]))));
        $this->assertSame(['Rosa Quispe'], $names($this->get(route('admin.users', ['q' => 'rosa@']))));
        $this->assertSame(['Rosa Quispe'], $names($this->get(route('admin.users', ['q' => '900111']))));
        $this->assertSame([], $names($this->get(route('admin.users', ['q' => '%']))), 'Los comodines LIKE se escapan.');

        $props = $this->get(route('admin.users'))->viewData('page')['props']['users']['data'][0];
        $this->assertArrayNotHasKey('password', $props);
        $this->assertArrayNotHasKey('phone_verification_code_hash', $props);
    }
}
