<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// P0-01 (auditoría Codex) — anti-replay TOTP persistente. El último timestep
// aceptado vive en la fila del usuario y se lee/escribe bajo lockForUpdate:
// varios workers serializan sobre la misma fila (antes: Cache get/put no
// atómico, 8/8 aceptados con el mismo código). No es secreto.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('two_factor_last_used_timestep')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('two_factor_last_used_timestep');
        });
    }
};
