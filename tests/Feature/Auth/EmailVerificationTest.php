<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_verification_screen_can_be_rendered(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $response = $this->actingAs($user)->get('/verify-email');

        $response->assertStatus(200);
    }

    public function test_email_can_be_verified(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(RouteServiceProvider::HOME.'?verified=1');
    }

    public function test_email_is_not_verified_with_invalid_hash(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('wrong-email')]
        );

        $this->actingAs($user)->get($verificationUrl);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_clicking_someone_elses_verification_link_logs_out_and_redirects_to_login_with_a_clear_message(): void
    {
        // Reproduce el 403 real reportado: alguien tiene OTRA cuenta abierta en el
        // navegador y hace clic en su propio correo de verificación. El
        // EmailVerificationRequest de Laravel por defecto lanza un 403 genérico
        // sin explicación — VerifyEmailRequest lo reemplaza por un redirect
        // a login con mensaje, cerrando la sesión equivocada.
        $loggedInUser = User::factory()->create(['email_verified_at' => null]);
        $linkOwner = User::factory()->create(['email_verified_at' => null]);

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $linkOwner->id, 'hash' => sha1($linkOwner->email)]
        );

        $response = $this->actingAs($loggedInUser)->get($verificationUrl);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');
        $this->assertGuest();
        $this->assertFalse($linkOwner->fresh()->hasVerifiedEmail());
    }

    /**
     * Cubre la personalización de AppServiceProvider::customizeVerifyEmailNotification()
     * (VerifyEmail::toMailUsing(), mecanismo oficial de Laravel) — antes el
     * correo real usaba el copy default en inglés de Laravel ("Verify your
     * email address"), confirmado en vivo en el gate Gmail. Renderiza el
     * MailMessage real que produciría la notificación (misma
     * VerifyEmail::toMail() -> verificationUrl() de siempre, sin
     * reconstruir la URL aparte) para demostrar que sigue siendo la ruta
     * firmada real de Laravel, no un string inventado.
     */
    public function test_verification_email_is_in_spanish_and_keeps_the_real_signed_url(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $mail = (new VerifyEmail)->toMail($user);

        $this->assertSame('MOVA — Verifica tu correo electrónico', $mail->subject);
        $this->assertSame('Hola, '.$user->name.'.', $mail->greeting);
        $this->assertContains('Confirma tu correo electrónico para continuar usando MOVA.', $mail->introLines);
        $this->assertSame('Verificar correo electrónico', $mail->actionText);
        $this->assertNotEmpty($mail->actionUrl);

        // La URL firmada real de Laravel — mismo mecanismo que
        // test_email_can_be_verified(), no una string construida a mano.
        $this->assertTrue(URL::hasValidSignature(\Illuminate\Http\Request::create($mail->actionUrl)));

        // El flujo de verificación en sí no cambia: visitar esa misma URL
        // sigue disparando Verified y marcando al usuario como verificado.
        Event::fake();
        $response = $this->actingAs($user)->get($mail->actionUrl);
        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(RouteServiceProvider::HOME.'?verified=1');
    }
}
