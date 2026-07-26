<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $email    = config('app.admin_email');
        $password = config('app.admin_password');
        $name     = env('ADMIN_NAME', 'Admin MOVA');

        if (! $email || ! $password) {
            $this->command->error('ADMIN_EMAIL and ADMIN_PASSWORD must be set in environment variables.');
            return;
        }

        // Roles
        foreach (['parent', 'teacher', 'admin'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        // Subjects
        $this->call(SubjectSeeder::class);

        // Admin user
        $admin = User::firstOrCreate(
            ['email' => $email],
            [
                'name'              => $name,
                'password'          => Hash::make($password),
                'email_verified_at' => now(),
            ]
        );

        if (! $admin->hasRole('admin')) {
            $admin->assignRole('admin');
        }

        $this->command->info("Admin user ready: {$email}");
    }
}
