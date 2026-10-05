<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['parent', 'teacher', 'admin'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        // La cuenta de desarrollo admin@mova.test con contraseña conocida SOLO existe en local/testing.
        // En producción/staging este seeder solo crea los roles: sembrar de más dejó una vez un
        // administrador con contraseña pública en la base de producción (eliminado el mismo día).
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $admin = User::firstOrCreate(
            ['email' => 'admin@mova.test'],
            [
                'name' => 'Admin MOVA',
                'password' => Hash::make('password'),
            ]
        );

        $admin->assignRole('admin');
    }
}
