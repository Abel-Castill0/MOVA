<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Añade las columnas que sostienen el ciclo de contraoferta de horario:
 *
 * - counteroffer_time             La hora exacta que el profesor propone.
 * - counteroffer_teacher_profile_id  Qué profesor hizo la propuesta (FK a
 *   teacher_profiles, no a users — igual que teacher_profile_id existente).
 * - meeting_link                  El link de la videollamada generado cuando
 *   el padre acepta la contraoferta (o acepta directamente).
 *
 * Las tres son nullable porque solo existen en etapas tardías del flujo:
 * una solicitud 'open' recién creada no tiene ninguna de las tres.
 *
 * FK a teacher_profiles con nullOnDelete: si el perfil de un profesor fuera
 * eliminado, la contraoferta queda sin referencia pero la solicitud no se
 * borra — el admin puede resolverla manualmente. nullOnDelete es el
 * comportamiento más conservador y el que usa teacher_profile_id en la
 * misma tabla (ver create_class_requests_table.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_requests', function (Blueprint $table) {
            $table->dateTime('counteroffer_time')->nullable()->after('status');
            $table->foreignId('counteroffer_teacher_profile_id')
                ->nullable()
                ->after('counteroffer_time')
                ->constrained('teacher_profiles')
                ->nullOnDelete();
            $table->string('meeting_link')->nullable()->after('counteroffer_teacher_profile_id');
        });
    }

    public function down(): void
    {
        Schema::table('class_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('counteroffer_teacher_profile_id');
            $table->dropColumn(['counteroffer_time', 'meeting_link']);
        });
    }
};
