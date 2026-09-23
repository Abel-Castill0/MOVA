<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }

    public function test_avatar_upload_falls_back_to_local_storage_without_cloudinary_credentials(): void
    {
        // El entorno de test nunca tiene CLOUDINARY_URL real configurada —
        // exactamente el escenario que el fallback debe cubrir.
        config(['services.cloudinary.cloud_url' => null]);
        Storage::fake('public');

        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('avatar.jpg', 100, 'image/jpeg');

        $response = $this->actingAs($user)->from('/profile')->post('/profile/avatar', ['avatar' => $file]);

        $response->assertSessionHasNoErrors()->assertRedirect('/profile');

        $user->refresh();
        $this->assertNotNull($user->avatar_url);
        $this->assertStringContainsString('/storage/avatars/', $user->avatar_url);
        Storage::disk('public')->assertExists("avatars/user-{$user->id}.jpg");
    }

    // AZ-2: en producción (contenedor efímero) el fallback a disco local está
    // prohibido — sin Cloudinary la subida debe fallar de forma controlada,
    // sin persistir nada y sin tumbar la request con un 500.
    public function test_avatar_upload_fails_closed_in_production_without_cloudinary(): void
    {
        config(['services.cloudinary.cloud_url' => null]);
        Storage::fake('public');
        // Fuera de 'testing' VerifyCsrfToken vuelve a exigir token (419);
        // el CSRF no es lo que se prueba aquí.
        $this->app['env'] = 'production';
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('avatar.jpg', 100, 'image/jpeg');

        $response = $this->actingAs($user)->from('/profile')->post('/profile/avatar', ['avatar' => $file]);

        $response->assertRedirect('/profile')->assertSessionHasErrors('avatar');

        $this->assertNull($user->fresh()->avatar_url);
        Storage::disk('public')->assertMissing("avatars/user-{$user->id}.jpg");
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    // UpdateAvatarForm.vue ahora se reutiliza en Teacher/Edit.vue
    // (/teacher/profile), no solo en /profile — updateAvatar() debe volver
    // a la página que lo llamó (back()), nunca a una ruta fija, o subir la
    // foto desde /teacher/profile sacaría al profesor a /profile a mitad de
    // su edición.
    public function test_avatar_upload_redirects_back_to_the_page_it_was_submitted_from(): void
    {
        config(['services.cloudinary.cloud_url' => null]);
        Storage::fake('public');

        $user = User::factory()->create();
        $user->assignRole(\Spatie\Permission\Models\Role::findOrCreate('teacher', 'web'));
        $file = UploadedFile::fake()->create('avatar.jpg', 100, 'image/jpeg');

        $response = $this->actingAs($user)->from('/teacher/profile')->post('/profile/avatar', ['avatar' => $file]);

        $response->assertSessionHasNoErrors()->assertRedirect('/teacher/profile');
        $this->assertNotNull($user->fresh()->avatar_url);
    }

    // Ciclo completo tal como lo usa UpdateAvatarForm.vue en la práctica:
    // subir y luego quitar, ambos pasos contra el mismo usuario, para probar
    // la transición completa, no cada extremo aislado.
    //
    // ->create(..., 'image/jpeg'), no ->image(): esta última necesita la
    // extensión GD de PHP para generar píxeles reales, que este entorno no
    // tiene instalada (por eso ningún otro test del proyecto la usa). La
    // validación de Laravel (regla `image`) solo mira MIME/extensión, nunca
    // decodifica el archivo, así que un archivo fake con MIME correcto basta
    // para probar el mismo camino — no es un test más débil.
    public function test_avatar_can_be_uploaded_then_removed_end_to_end(): void
    {
        config(['services.cloudinary.cloud_url' => null]);
        Storage::fake('public');

        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('foto.jpg', 200, 'image/jpeg');

        $this->actingAs($user)->from('/profile')->post('/profile/avatar', ['avatar' => $file])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();
        $this->assertNotNull($user->avatar_url);
        Storage::disk('public')->assertExists("avatars/user-{$user->id}.jpg");

        $this->actingAs($user)->from('/profile')->delete('/profile/avatar')
            ->assertRedirect('/profile');

        $this->assertNull($user->fresh()->avatar_url);
        // removeAvatar() no borra el archivo (ver TODO en ProfileController)
        // — la sola desvinculación del avatar_url es lo que este test prueba.
        Storage::disk('public')->assertExists("avatars/user-{$user->id}.jpg");
    }

    public function test_removing_the_avatar_clears_avatar_url_and_redirects_back(): void
    {
        $user = User::factory()->create(['avatar_url' => 'https://res.cloudinary.com/demo/avatar.jpg']);

        $response = $this->actingAs($user)->from('/profile')->delete('/profile/avatar');

        $response->assertRedirect('/profile');
        $this->assertNull($user->fresh()->avatar_url);
    }

    public function test_removing_the_avatar_requires_authentication(): void
    {
        $response = $this->delete('/profile/avatar');

        $response->assertRedirect('/login');
    }

    public function test_avatar_upload_rejects_non_image_files(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->actingAs($user)->post('/profile/avatar', ['avatar' => $file]);

        $response->assertSessionHasErrors('avatar');
        $this->assertNull($user->fresh()->avatar_url);
    }

    public function test_avatar_upload_requires_authentication(): void
    {
        $file = UploadedFile::fake()->create('avatar.jpg', 100, 'image/jpeg');

        $response = $this->post('/profile/avatar', ['avatar' => $file]);

        $response->assertRedirect('/login');
    }
}
