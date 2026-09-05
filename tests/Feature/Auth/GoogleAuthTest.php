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

    /**
     * R-19 — CAMBIO DE CONTRATO DELIBERADO.
     *
     * Este test afirmaba antes que una cuenta nueva de Google se creaba
     * directamente con rol `parent`. Ese era exactamente el hallazgo: un
     * profesor que entrara por Google quedaba clasificado como padre, sin
     * TeacherProfile y sin forma de corregirlo (docs/MOVA_SYSTEM_MAP.md R-19).
     *
     * Ahora el callback NO crea nada: guarda la identidad verificada en la
     * sesión y lleva a elegir rol. No se crea ninguna fila hasta que la persona
     * decide, así que abandonar a mitad no deja usuarios sin rol —un estado
     * inválido en MOVA, donde `DashboardController` mostraría el panel de padre
     * a cualquiera que no sea admin ni profesor.
     */
    public function test_callback_does_not_create_an_account_until_the_role_is_chosen(): void
    {
        Event::fake([Registered::class]);
        $this->fakeGoogleUser('nueva.familia@gmail.com', 'Nueva Familia');

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('auth.google.role'));
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'nueva.familia@gmail.com']);
        Event::assertNotDispatched(Registered::class);
    }

    public function test_the_role_screen_shows_the_verified_google_identity(): void
    {
        $this->fakeGoogleUser('nueva.familia@gmail.com', 'Nueva Familia');
        $this->get(route('auth.google.callback'));

        $this->get(route('auth.google.role'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/GoogleRole')
                ->where('email', 'nueva.familia@gmail.com')
                ->where('name', 'Nueva Familia')
            );
    }

    public function test_choosing_parent_creates_the_account_and_starts_parent_onboarding(): void
    {
        Event::fake([Registered::class]);
        $this->fakeGoogleUser('familia@gmail.com', 'Familia Test');
        $this->get(route('auth.google.callback'));

        $response = $this->post(route('auth.google.role.store'), [
            'role' => 'parent',
            'accepted_terms' => true,
        ]);

        $response->assertRedirect(route('students.create'));
        $this->assertAuthenticated();

        $user = User::where('email', 'familia@gmail.com')->firstOrFail();
        $this->assertTrue($user->hasRole('parent'));
        $this->assertNotNull($user->email_verified_at, 'Google ya verificó el correo.');
        $this->assertNull($user->teacherProfile);
        Event::assertDispatched(Registered::class);
    }

    /**
     * El caso que el hallazgo describía: un profesor entrando por Google.
     */
    public function test_choosing_teacher_creates_a_teacher_profile_and_starts_teacher_onboarding(): void
    {
        $this->fakeGoogleUser('profe@gmail.com', 'Profe Test');
        $this->get(route('auth.google.callback'));

        $response = $this->post(route('auth.google.role.store'), [
            'role' => 'teacher',
            'accepted_terms' => true,
        ]);

        $response->assertRedirect(route('teacher.setup'));

        $user = User::where('email', 'profe@gmail.com')->firstOrFail();
        $this->assertTrue($user->hasRole('teacher'));
        $this->assertFalse($user->hasRole('parent'));
        $this->assertNotNull($user->teacherProfile, 'Un profesor sin perfil no puede operar.');
        $this->assertFalse((bool) $user->teacherProfile->is_verified, 'La verificación sigue siendo del admin.');
    }

    /**
     * NADIE SE AUTOASIGNA ADMIN. La validación es una lista blanca explícita,
     * no una lista derivada de los roles existentes.
     *
     * @dataProvider forbiddenRoles
     */
    public function test_a_role_outside_the_allow_list_is_rejected(string $role): void
    {
        $this->fakeGoogleUser('intruso@gmail.com', 'Intruso');
        $this->get(route('auth.google.callback'));

        $this->post(route('auth.google.role.store'), [
            'role' => $role,
            'accepted_terms' => true,
        ])->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'intruso@gmail.com']);
        $this->assertGuest();
    }

    public static function forbiddenRoles(): array
    {
        return [
            'admin' => ['admin'],
            'rol inexistente' => ['superadmin'],
            'vacío' => [''],
        ];
    }

    public function test_the_terms_must_be_accepted(): void
    {
        $this->fakeGoogleUser('sinterminos@gmail.com', 'Sin Terminos');
        $this->get(route('auth.google.callback'));

        $this->post(route('auth.google.role.store'), ['role' => 'parent'])
            ->assertSessionHasErrors('accepted_terms');

        $this->assertDatabaseMissing('users', ['email' => 'sinterminos@gmail.com']);
    }

    public function test_the_role_screen_is_unusable_without_a_verified_google_identity(): void
    {
        // Sin pasar por el callback: no hay identidad en sesión que suplantar.
        $this->get(route('auth.google.role'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->post(route('auth.google.role.store'), ['role' => 'teacher', 'accepted_terms' => true])
            ->assertRedirect(route('login'));

        $this->assertSame(0, User::count());
    }

    /**
     * El formulario solo envía el rol. Si alguien inyecta un email, se ignora:
     * la identidad viene de la sesión escrita por el callback.
     */
    public function test_the_email_cannot_be_injected_through_the_role_form(): void
    {
        $this->fakeGoogleUser('real@gmail.com', 'Real');
        $this->get(route('auth.google.callback'));

        $this->post(route('auth.google.role.store'), [
            'role' => 'parent',
            'accepted_terms' => true,
            'email' => 'suplantado@gmail.com',
            'name' => 'Suplantado',
        ]);

        $this->assertDatabaseHas('users', ['email' => 'real@gmail.com']);
        $this->assertDatabaseMissing('users', ['email' => 'suplantado@gmail.com']);
    }

    /**
     * Carrera: entre el callback y la elección de rol, alguien registra ese
     * mismo correo por el formulario normal.
     */
    public function test_an_email_registered_meanwhile_does_not_produce_a_duplicate(): void
    {
        $this->fakeGoogleUser('carrera@gmail.com', 'Carrera');
        $this->get(route('auth.google.callback'));

        $meanwhile = User::factory()->create(['email' => 'carrera@gmail.com']);
        $meanwhile->assignRole('teacher');

        $this->post(route('auth.google.role.store'), [
            'role' => 'parent',
            'accepted_terms' => true,
        ])->assertRedirect(route('login'));

        $this->assertSame(1, User::where('email', 'carrera@gmail.com')->count());
        $this->assertTrue($meanwhile->fresh()->hasRole('teacher'), 'El rol de la cuenta existente no se toca.');
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
