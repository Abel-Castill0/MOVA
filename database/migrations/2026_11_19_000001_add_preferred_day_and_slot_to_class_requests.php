<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Día + franja preferidos de una solicitud de clase. Aditiva: las solicitudes históricas conservan `preferred_times`
 * (lista de franjas semanales) y quedan con estas dos columnas en NULL; nada se reescribe ni se borra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_requests', function (Blueprint $table) {
            $table->date('preferred_date')->nullable()->after('preferred_times');
            $table->string('preferred_slot', 16)->nullable()->after('preferred_date');
        });
    }

    public function down(): void
    {
        Schema::table('class_requests', function (Blueprint $table) {
            $table->dropColumn(['preferred_date', 'preferred_slot']);
        });
    }
};
