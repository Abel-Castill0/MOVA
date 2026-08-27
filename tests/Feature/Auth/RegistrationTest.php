<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        Notification::fake();

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'role' => 'parent',
            'accepted_terms' => true,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('students.create'));
    }

    /**
     * Cubre el precondición del lado servidor de un bug real encontrado en
     * el navegador (no en este archivo — PHPUnit no ejecuta Vue): el
     * wizard de Register.vue dejaba al usuario varado en el último paso,
     * sin ningún mensaje, cuando el error de validación caía en un campo
     * de un paso anterior (aquí: email duplicado, paso 3, pero el usuario
     * ya estaba en el paso 5/5). El fix real vive en Register.vue
     * (stepForField + onError mueve `step` al paso con el error); esta
     * prueba solo confirma la mitad que SÍ es alcanzable desde PHPUnit —
     * que el servidor efectivamente rechaza el email duplicado con un
     * error de validación real (redirect-back + session errors), que es
     * la precondición de la que depende el bug del frontend. No hay
     * runner de tests JS en este proyecto (verificado: sin vitest/jest en
     * package.json) para probar stepForField/onError directamente.
     */
    public function test_registration_with_a_duplicate_email_fails_with_a_clear_message(): void
    {
        \App\Models\User::factory()->create(['email' => 'ya-existe@example.com']);

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'ya-existe@example.com',
            'role' => 'parent',
            'accepted_terms' => true,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect()->assertSessionHasErrors(['email']);
        $this->assertGuest();
        $this->assertSame(1, \App\Models\User::where('email', 'ya-existe@example.com')->count());
    }
}
