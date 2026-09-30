<?php

namespace Tests\Feature;

use App\Models\LegalAcceptance;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * C-P0-EMAIL — los flujos síncronos solo-correo (registro, reenvío de
 * verificación, recuperación de contraseña) se recuperan de un fallo de
 * ENTREGA sin un 500 y sin afirmar que el correo salió. Transporte sintético.
 */
class SynchronousMailFailureRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public const PROVIDER_DETAIL = 'provider-raw-detail-xyz';

    public static bool $providerDown = true;

    protected function setUp(): void
    {
        parent::setUp();

        self::$providerDown = true;

        Mail::extend('flaky', fn () => new FlakyTransport());
        config(['mail.mailers.flaky' => ['transport' => 'flaky'], 'mail.default' => 'flaky']);
    }

    private function registration(string $role = 'parent'): array
    {
        return [
            'name' => 'Test User',
            'email' => 'new-user@example.com',
            'role' => $role,
            'accepted_terms' => true,
            'password' => 'password',
            'password_confirmation' => 'password',
        ] + ($role === 'teacher' ? ['teacher_subject_names' => ['Matemática']] : []);
    }

    private function assertNoLeak(): void
    {
        $this->assertStringNotContainsString(self::PROVIDER_DETAIL, json_encode(session()->all()));
        $this->assertStringNotContainsString('new-user@example.com', (string) json_encode(session('error')));
    }

    // ── Registro ────────────────────────────────────────────────────────

    public function test_registration_survives_a_verification_mail_failure(): void
    {
        $response = $this->post('/register', $this->registration());

        $response->assertRedirect(route('verification.notice'));
        $response->assertSessionHas('error');
        $response->assertSessionMissing('status');
        $this->assertAuthenticated();

        $user = User::where('email', 'new-user@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('parent'));
        $this->assertGreaterThan(0, LegalAcceptance::where('user_id', $user->id)->count());
        $this->assertSame($user->id, auth()->id());
        $this->assertNotNull($user->fresh()->welcome_notification_sent_at);
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $user->id)->count(), 'Un solo aviso de bienvenida in-app.');
        $this->assertNoLeak();
    }

    public function test_teacher_registration_also_survives_and_keeps_its_state(): void
    {
        $this->post('/register', $this->registration('teacher'))->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'new-user@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('teacher'));
        $this->assertNotNull($user->teacherProfile);
        $this->assertAuthenticated();
    }

    public function test_registration_with_a_working_provider_is_unchanged(): void
    {
        self::$providerDown = false;

        $this->post('/register', $this->registration())
            ->assertRedirect(route('students.create'))
            ->assertSessionMissing('error');

        $this->assertAuthenticated();
    }

    public function test_a_non_mail_exception_from_a_registered_listener_is_not_swallowed(): void
    {
        self::$providerDown = false;
        Event::listen(Registered::class, fn () => throw new RuntimeException('unrelated listener failure'));
        $this->withoutExceptionHandling();

        $this->expectExceptionMessage('unrelated listener failure');

        $this->post('/register', $this->registration());
    }

    // ── Reenvío de verificación ─────────────────────────────────────────

    public function test_resend_failure_is_recoverable_and_never_claims_success(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->from('/verify-email')->post(route('verification.send'));

        $response->assertRedirect('/verify-email');
        $response->assertSessionHas('error');
        $response->assertSessionMissing('status');
        $this->assertAuthenticatedAs($user);
        $this->assertNoLeak();
    }

    public function test_resend_success_keeps_its_status(): void
    {
        self::$providerDown = false;
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->from('/verify-email')->post(route('verification.send'))
            ->assertSessionHas('status', 'verification-link-sent')
            ->assertSessionMissing('error');
    }

    // ── Recuperación de contraseña ──────────────────────────────────────

    public function test_password_reset_failure_is_a_generic_recoverable_error_not_a_false_success(): void
    {
        $user = User::factory()->create();

        $response = $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email]);

        $response->assertRedirect('/forgot-password');
        $response->assertSessionHasErrors('email');
        $response->assertSessionMissing('status');
        $errors = json_encode(session('errors')->getBag('default')->all());
        $this->assertStringNotContainsString(self::PROVIDER_DETAIL, $errors);
        $this->assertStringNotContainsString($user->email, $errors);
    }

    public function test_password_reset_success_keeps_its_status(): void
    {
        self::$providerDown = false;
        $user = User::factory()->create();

        $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();
    }
}

class FlakyTransport extends AbstractTransport
{
    protected function doSend(SentMessage $message): void
    {
        if (SynchronousMailFailureRecoveryTest::$providerDown) {
            throw new RuntimeException(SynchronousMailFailureRecoveryTest::PROVIDER_DETAIL);
        }
    }

    public function __toString(): string
    {
        return 'flaky';
    }
}
