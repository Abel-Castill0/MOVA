<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * P0-D / F-27 — rotar una contraseña comprometida debe expulsar las sesiones
 * ya emitidas (AuthenticateSession en el grupo web), no solo impedir logins
 * nuevos.
 */
class SessionInvalidationOnPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_session_is_invalidated_after_password_rotation(): void
    {
        $user = User::factory()->create(['password' => 'old-password-123']);
        // Sesión emitida ANTES de la rotación: AuthenticateSession guardó el
        // hash de la contraseña vigente en ese momento.
        $sessionHashAtLogin = $user->getAuthPassword();

        // Rotación fuera de esta sesión (p. ej. el dueño cambia la clave desde otro equipo).
        $user->forceFill(['password' => Hash::make('new-password-456')])->save();

        $this->actingAs($user)
            ->withSession(['password_hash_web' => $sessionHashAtLogin])
            ->get('/profile')
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
