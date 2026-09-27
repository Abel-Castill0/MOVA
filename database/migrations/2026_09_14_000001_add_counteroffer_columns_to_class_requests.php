<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Añade las columnas que sostienen el ciclo de contraoferta de horario:
 *
 * - counteroffer_time               La hora EXACTA de inicio que el profesor
 *   propone.
 * - counteroffer_duration_minutes   La duración propuesta (30..240, mismo
 *   rango que LessonController::store()) — sin ella el padre aceptaría una
 *   hora sin saber cuánto dura ni cuántos créditos cuesta la clase.
 * - counteroffer_teacher_profile_id Qué profesor hizo la propuesta (FK a
 *   teacher_profiles, igual que teacher_profile_id existente). Es el
 *   profesor cuya agenda, créditos y elegibilidad se verifican cuando el
 *   padre acepta — nunca el usuario que envía la aceptación.
 *
 * DELIBERADAMENTE SIN meeting_link: la sala de videollamada pertenece a la
 * Lesson (jitsi_room, arquitectura JaaS actual), no a la solicitud. La
 * versión original de esta migración guardaba aquí un link de meet.jit.si
 * generado al aceptar, saltándose la creación de la Lesson entera (créditos,
 * reserva, agenda, precio congelado). Corregida antes de su primera
 * aplicación en cualquier entorno compartido — nunca se desplegó.
 *
 * Las tres son nullable: una solicitud 'open' recién creada no tiene
 * ninguna, y rechazar una contraoferta las vuelve a limpiar.
 *
 * nullOnDelete en la FK: si el perfil del profesor se eliminara, la
 * contraoferta queda sin referencia pero la solicitud no se borra — es el
 * comportamiento más conservador y el mismo que teacher_profile_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_requests', function (Blueprint $table) {
            $table->dateTime('counteroffer_time')->nullable()->after('status');
            $table->unsignedInteger('counteroffer_duration_minutes')->nullable()->after('counteroffer_time');
            $table->foreignId('counteroffer_teacher_profile_id')
                ->nullable()
                ->after('counteroffer_duration_minutes')
                ->constrained('teacher_profiles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('class_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('counteroffer_teacher_profile_id');
            $table->dropColumn(['counteroffer_time', 'counteroffer_duration_minutes']);
        });
    }
};
