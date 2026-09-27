<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// P0-J — latidos de procesos (scheduler, worker). Tabla propia en vez de
// Cache: la señal no debe depender de que CACHE_DRIVER sea compartido.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_heartbeats', function (Blueprint $table) {
            $table->string('name', 64)->primary();
            $table->timestamp('beat_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_heartbeats');
    }
};
