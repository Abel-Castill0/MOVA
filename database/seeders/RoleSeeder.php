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
