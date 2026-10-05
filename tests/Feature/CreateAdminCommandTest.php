<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_verified_admin_with_an_unknown_password(): void
    {
        $this->artisan('mova:create-admin', ['email' => 'Dueno@Real.PE', '--name' => 'Dueño'])->assertSuccessful();

        $user = User::where('email', 'dueno@real.pe')->firstOrFail();
        $this->assertTrue($user->hasRole('admin'));
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertGreaterThan(40, strlen($user->password));
    }

    public function test_it_is_idempotent_and_never_changes_an_existing_password(): void
    {
        $existing = User::factory()->create(['email' => 'ya@real.pe', 'password' => 'clave-existente-1']);
        $hash = $existing->password;

        $this->artisan('mova:create-admin', ['email' => 'ya@real.pe'])->assertSuccessful();
        $this->artisan('mova:create-admin', ['email' => 'ya@real.pe'])->assertSuccessful();

        $existing->refresh();
        $this->assertTrue($existing->hasRole('admin'));
        $this->assertSame($hash, $existing->password);
        $this->assertSame(1, User::where('email', 'ya@real.pe')->count());
    }

    public function test_it_rejects_invalid_emails_and_test_accounts_in_production(): void
    {
        $this->artisan('mova:create-admin', ['email' => 'no-es-un-correo'])->assertFailed();

        $this->app['env'] = 'production';
        $this->artisan('mova:create-admin', ['email' => 'admin@mova.test'])->assertFailed();
        $this->artisan('mova:create-admin', ['email' => 'x@example.com'])->assertFailed();

        $this->assertSame(0, User::count());
    }
}
