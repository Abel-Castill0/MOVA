<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        Notification::fake();
    }

    private function fakeGoogleUser(string $email, string $name = 'Google User'): void
    {
        $socialiteUser = \Mockery::mock(SocialiteUser::class);
        $socialiteUser->shouldReceive('getEmail')->andReturn($email);
        $socialiteUser->shouldReceive('getName')->andReturn($name);

        Socialite::shouldReceive('driver->stateless->user')->andReturn($socialiteUser);
    }

    // "Stand by": el login con Google está desactivado por defecto
    // (config('services.google.login_enabled') = false salvo que
    // GOOGLE_LOGIN_ENABLED=true) — el frontend ya no navega aquí (muestra un
    // modal), pero un enlace directo a /auth/google tampoco debe funcionar.
    public function test_redirect_is_disabled_by_default(): void
    {
        $response = $this->get(route('auth.google'));

        $response->assertStatus(503);
    }

    public function test_redirect_sends_user_to_google_when_explicitly_enabled(): void
    {
        config(['services.google.login_enabled' => true]);

        // No mockeamos driver()->redirect(): sin credenciales reales de Google
        // configuradas, Socialite igual construye la URL (con client_id vacío)
        // sin lanzar excepción — solo verificamos que la ruta responde con un
        // redirect, no que la URL sea válida ante Google.
        $response = $this->get(route('auth.google'));

        $response->assertRedirect();
        $this->assertStringContainsString('accounts.google.com', $response->headers->get('Location'));
    }

    public function test_callback_creates_new_user_with_parent_role_and_verified_email(): void
    {
        Event::fake([Registered::class]);
        $this->fakeGoogleUser('nueva.familia@gmail.com', 'Nueva Familia');

        $response = $this->get(route('auth.google.callback'));

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard'));

        $user = User::where('email', 'nueva.familia@gmail.com')->firstOrFail();
        $this->assertTrue($user->hasRole('parent'));
        $this->assertFalse($user->hasRole('teacher'));
        $this->assertNotNull($user->email_verified_at);
        Event::assertDispatched(Registered::class);
    }

    public function test_callback_logs_in_existing_user_without_creating_duplicate(): void
    {
        $existing = User::factory()->create(['email' => 'ya.existe@gmail.com']);
        $existing->assignRole('teacher');

        $this->fakeGoogleUser('ya.existe@gmail.com', 'Nombre Distinto En Google');

        $response = $this->get(route('auth.google.callback'));

        $this->assertAuthenticatedAs($existing);
        $response->assertRedirect(route('dashboard'));

        $this->assertSame(1, User::where('email', 'ya.existe@gmail.com')->count());
        // El nombre y rol de la cuenta existente no se sobrescriben con lo que
        // venga de Google — firstOrCreate solo usa esos datos si crea la fila.
        $this->assertNotSame('Nombre Distinto En Google', $existing->fresh()->name);
        $this->assertTrue($existing->fresh()->hasRole('teacher'));
    }

    public function test_callback_verifies_email_of_existing_unverified_user(): void
    {
        $existing = User::factory()->unverified()->create(['email' => 'sin.verificar@gmail.com']);
        $existing->assignRole('parent');
        $this->assertNull($existing->email_verified_at);

        $this->fakeGoogleUser('sin.verificar@gmail.com');

        $this->get(route('auth.google.callback'));

        $this->assertNotNull($existing->fresh()->email_verified_at);
    }

    public function test_callback_redirects_to_login_with_error_on_google_failure(): void
    {
        Socialite::shouldReceive('driver->stateless->user')->andThrow(new \Exception('invalid state'));

        $response = $this->get(route('auth.google.callback'));

        $this->assertGuest();
        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
    }
}
