<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LocalTestDataSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Los seeders de desarrollo crean cuentas con contraseñas conocidas: no pueden crearlas en
 * un entorno real. (Incidente: RoleSeeder dejó admin@mova.test/«password» en producción.)
 */
class SeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_seeder_creates_the_dev_admin_only_in_local_and_testing(): void
    {
        (new RoleSeeder())->run();
        $this->assertSame(1, User::where('email', 'admin@mova.test')->count());
    }

    public function test_in_production_role_seeder_creates_roles_but_never_a_user(): void
    {
        $this->app['env'] = 'production';

        (new RoleSeeder())->run();

        $this->assertSame(3, Role::whereIn('name', ['parent', 'teacher', 'admin'])->count());
        $this->assertSame(0, User::count());
    }

    public function test_dev_data_seeders_refuse_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        foreach ([DatabaseSeeder::class, LocalTestDataSeeder::class] as $seeder) {
            try {
                (new $seeder())->run();
                $this->fail("$seeder debía negarse a correr en producción.");
            } catch (HttpException $e) {
                $this->assertSame(500, $e->getStatusCode());
            }
        }

        $this->assertSame(0, User::count());
    }
}
