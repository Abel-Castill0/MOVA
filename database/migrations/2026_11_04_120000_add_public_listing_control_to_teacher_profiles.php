<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table) {
            // Preserva las fichas existentes; un administrador puede ocultar
            // selectivamente los perfiles QA sin desverificar ni borrar datos.
            $table->boolean('is_publicly_listed')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('teacher_profiles', fn (Blueprint $table) => $table->dropColumn('is_publicly_listed'));
    }
};
