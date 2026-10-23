<?php

namespace Tests\Feature\Auth;

use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** El correo verificado ya NO es requisito de acceso; sigue significando lo que significa (`email_verified_at`). */
class OptionalEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $bypassAdminMfa = false;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['parent', 'teacher', 'admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function unverified(string $role): User
    {
        $user = User::factory()->unverified()->create(['password' => bcrypt('Secreta-123!')]);
        $user->assignRole($role);
        if ($role === 'teacher') {
            TeacherProfile::create(['user_id' => $user->id, 'is_verified' => false, 'hourly_rate' => 20, 'credits_available' => 0, 'credits_reserved' => 0]);
        }

        return $user;
    }

    private function registration(string $role, string $email): array
    {
        return ['name' => 'Persona Nueva', 'email' => $email, 'password' => 'Str0ng-Pass-2026!', 'password_confirmation' => 'Str0ng-Pass-2026!', 'role' => $role, 'accepted_terms' => true]
            + ($role === 'teacher' ? ['teacher_subject_names' => ['Matemáticas']] : []);
    }

    public function test_new_parent_registers_and_enters_without_verifying_email(): void
    {
        $this->post('/register', $this->registration('parent', 'padre-nuevo@example.test'))->assertRedirect(route('students.create'));
        $this->assertAuthenticated();
        $user = User::where('email', 'padre-nuevo@example.test')->firstOrFail();
        $this->assertNull($user->email_verified_at, 'No se marca como verificado un correo que nunca lo fue.');
        $this->get(route('students.create'))->assertOk();
        $this->get('/dashboard')->assertOk();
    }

    public function test_new_teacher_registers_and_enters_but_keeps_professional_approval_pending(): void
    {
        $this->post('/register', $this->registration('teacher', 'profe-nuevo@example.test'))->assertRedirect(route('teacher.setup'));
        $user = User::where('email', 'profe-nuevo@example.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertFalse((bool) $user->teacherProfile->is_verified);
        $this->get('/dashboard')->assertOk();
    }

    public function test_existing_unverified_parent_and_teacher_log_in_straight_to_the_dashboard(): void
    {
        foreach (['parent', 'teacher'] as $role) {
            $user = $this->unverified($role);
            $this->post('/login', ['email' => $user->email, 'password' => 'Secreta-123!'])->assertRedirect('/dashboard');
            $this->get('/dashboard')->assertOk();
            $this->get('/profile')->assertOk();
            $this->assertNull($user->fresh()->email_verified_at);
            $this->post('/logout');
        }
    }

    public function test_verified_users_still_log_in_normally(): void
    {
        $user = User::factory()->create(['password' => bcrypt('Secreta-123!')]);
        $user->assignRole('parent');
        $this->post('/login', ['email' => $user->email, 'password' => 'Secreta-123!'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
    }

    public function test_wrong_password_and_unknown_user_are_rejected(): void
    {
        $user = $this->unverified('parent');
        $this->post('/login', ['email' => $user->email, 'password' => 'incorrecta'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => 'nadie@example.test', 'password' => 'Secreta-123!'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_the_verification_page_is_never_forced_and_never_loops(): void
    {
        $user = $this->unverified('parent');
        foreach (['/dashboard', '/profile', '/class-requests', '/students'] as $path) {
            $response = $this->actingAs($user)->get($path);
            $this->assertNotSame(route('verification.notice'), $response->headers->get('Location'), $path);
        }
        // The page still exists as a voluntary confirmation step and does not redirect anywhere else.
        $this->actingAs($user)->get(route('verification.notice'))->assertOk();
    }

    public function test_suspended_and_closed_accounts_stay_blocked(): void
    {
        $suspended = $this->unverified('parent');
        $suspended->forceFill(['suspended_at' => now(), 'suspension_reason' => 'QA'])->save();
        $this->actingAs($suspended)->get('/class-requests')->assertRedirect();
        $this->assertNotSame(200, $this->actingAs($suspended)->get('/students')->getStatusCode());

        $closed = $this->unverified('teacher');
        $closed->forceFill(['account_closed_at' => now()])->save();
        $this->actingAs($closed)->get('/dashboard')->assertRedirect(route('account.closure.status'));
    }

    public function test_admin_without_mfa_is_still_sent_to_enrolment_even_if_email_is_unverified(): void
    {
        $admin = $this->unverified('admin');
        $this->actingAs($admin)->get(route('admin.users'))->assertRedirect(route('admin.mfa.setup'));
        $this->get('/dashboard')->assertRedirect(route('admin.mfa.setup'));
    }

    public function test_password_recovery_works_for_unverified_accounts_and_never_logs_anyone_in(): void
    {
        Notification::fake();
        $user = $this->unverified('parent');
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();
        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertGuest();
    }

    public function test_changing_the_email_still_needs_the_current_password_and_resets_verification(): void
    {
        $user = $this->unverified('parent');
        $user->forceFill(['email_verified_at' => now()])->save();
        $data = ['name' => $user->name, 'email' => 'otro@example.test'];
        $this->actingAs($user)->patch('/profile', $data)->assertSessionHasErrors('current_password');
        $this->patch('/profile', $data + ['current_password' => 'Secreta-123!'])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_an_unverified_account_gets_no_data_from_other_accounts_with_the_same_name(): void
    {
        $other = User::factory()->create(['name' => 'Persona Nueva']);
        $other->assignRole('parent');
        $this->post('/register', $this->registration('parent', 'distinta@example.test'));
        $fresh = User::where('email', 'distinta@example.test')->firstOrFail();
        $this->assertSame(0, $fresh->students()->count());
        $this->assertNotSame($other->id, $fresh->id);
    }
}
