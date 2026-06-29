<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure Spatie roles exist
        foreach (['parent', 'teacher', 'admin'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }

        // Assign admin role to the env-configured admin user
        $email = env('ADMIN_EMAIL');
        if (! $email) {
            return;
        }

        $admin = \App\Models\User::where('email', $email)->first();
        if ($admin && ! $admin->hasRole('admin')) {
            $admin->assignRole('admin');
        }
    }

    public function down(): void
    {
        // roles are not removed on rollback to avoid data loss
    }
};
