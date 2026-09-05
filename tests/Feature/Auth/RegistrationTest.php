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

    /**
     * Cubre app/Http/Controllers/Auth/RegisteredUserController.php:72 (el
     * abort_if() convertido a ValidationException tras encontrar, en el
     * mismo controlador, el bug ya corregido de Register.vue: un mensaje
     * que nunca llega a form.errors porque no es una ValidationException
     * real). El array pasa el validate() inicial intacto (cada elemento es
     * un string bajo max:100, no vacío según trim() dentro de NotProfane,
     * y no está en la lista de palabras bloqueadas — NotProfane.php
     * confirma que retorna sin fallar ante un string solo de espacios),
     * pero termina vacío tras el trim()->filter() de store() que arma
     * $subjectIds — el escenario real que el wizard ya bloquea en el
     * cliente (addSubject()/isCurrentStepValid), pero que el backend debe
     * rechazar igual de bien si se alcanza por otra vía (una request
     * directa, por ejemplo). No existía ninguna prueba para este camino
     * (grepeado tests/Feature antes de asumir que no había ninguna).
     */
    public function test_teacher_registration_with_only_blank_subject_names_fails_with_a_clear_message(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test Teacher',
            'email' => 'blank-subjects@example.com',
            'role' => 'teacher',
            'accepted_terms' => true,
            'password' => 'password',
            'password_confirmation' => 'password',
            'teacher_subject_names' => ['   ', ''],
        ]);

        $response->assertRedirect()->assertSessionHasErrors(['teacher_subject_names']);
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('teacher_profiles', 0);
    }

    /**
     * H-12 (bug hermano) — El registro aceptaba cualquier cadena de <=20
     * caracteres como teléfono, así que creaba cuentas con un número que
     * NUNCA podría verificarse por WhatsApp. Ahora comparte la misma regla
     * que el perfil, y el problema se corta en el origen.
     */
    public function test_registration_rejects_a_phone_that_could_never_be_verified(): void
    {
        $this->post('/register', [
            'name' => 'Padre Prueba',
            'email' => 'padre.prueba@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'phone' => '12345',
            'role' => 'parent',
            'accepted_terms' => true,
        ])->assertSessionHasErrors('phone');

        $this->assertDatabaseMissing('users', ['email' => 'padre.prueba@example.com']);
    }

    public function test_registration_still_allows_omitting_the_phone(): void
    {
        $this->post('/register', [
            'name' => 'Padre Sin Telefono',
            'email' => 'sin.telefono@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'parent',
            'accepted_terms' => true,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'sin.telefono@example.com', 'phone' => null]);
    }
}
