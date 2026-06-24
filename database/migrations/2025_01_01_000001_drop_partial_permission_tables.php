<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// Cleans up partial Spatie permission tables left by failed migrations.
// Safe on clean installs: dropIfExists is a no-op when tables don't exist.
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }

    public function down(): void
    {
        // Intentionally empty: down() is not used in production.
    }
};
