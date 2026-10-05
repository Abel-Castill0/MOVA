<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El correo de recuperación de contraseña es del framework y salía en inglés
 * ("Reset your password", "Hello!", "All rights reserved."), mientras el resto
 * de correos de MOVA están en español. lang/es.json cubre sus textos.
 */
class PasswordResetMailLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_password_reset_email_is_fully_in_spanish(): void
    {
        $this->assertSame('es', app()->getLocale());

        $user = User::factory()->create(['email' => 'familia@example.test']);
        $mail = (new ResetPassword('token-de-prueba'))->toMail($user);

        $this->assertSame('Restablece tu contraseña', $mail->subject);

        $html = (string) $mail->render();
        foreach (['¡Hola!', 'Restablecer contraseña', 'vence en 60 minutos', 'Saludos,', 'Todos los derechos reservados.', 'copia y pega esta dirección'] as $text) {
            $this->assertStringContainsString($text, $html, "Falta la traducción: {$text}");
        }

        foreach (['Reset your password', 'Hello!', 'Regards,', 'All rights reserved', 'no further action'] as $english) {
            $this->assertStringNotContainsString($english, $html, "Queda texto en inglés: {$english}");
        }
    }

    public function test_the_reset_link_still_points_to_the_reset_route_with_the_token_and_email(): void
    {
        $user = User::factory()->create(['email' => 'familia@example.test']);

        $html = (string) (new ResetPassword('abc123'))->toMail($user)->render();

        $this->assertStringContainsString('/reset-password/abc123', $html);
        $this->assertStringContainsString('email=familia%40example.test', $html);
    }
}
