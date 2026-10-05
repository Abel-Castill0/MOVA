<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Disponibilidad semanal central del profesor (declarada por él mismo).
     *
     * Hasta ahora la única disponibilidad vivía en `class_offers.availability_schedule`
     * y las ofertas nuevas ya no se crean, así que el matching no tenía una
     * fuente vigente. Esta tabla es solo informativa: orienta recomendaciones
     * y se muestra en el perfil; NO bloquea ni autoriza agendas (la fuente de
     * verdad de choques de horario sigue siendo LessonSchedulingService).
     */
    public function up(): void
    {
        Schema::create('teacher_availability_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_profile_id')->constrained()->cascadeOnDelete();
            // 0 = domingo … 6 = sábado (Carbon::dayOfWeek, hora de Lima).
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->unique(['teacher_profile_id', 'day_of_week', 'start_time'], 'teacher_avail_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_availability_slots');
    }
};
