<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Evidencia de presencia recibida de JaaS (webhooks PARTICIPANT_JOINED /
     * PARTICIPANT_LEFT). Es SOLO evidencia: nada en MOVA decide asistencia,
     * ausencia, créditos ni liquidación a partir de esta tabla — esas reglas de
     * negocio no están definidas (ver ledger C-P1-ATTENDANCE-DISPUTES).
     *
     * Minimización de datos (menores de edad): no se guarda el nombre, el
     * correo ni el avatar que envía JaaS. Solo qué clase, qué usuario de MOVA
     * (resuelto por el id que MOVA puso en su propio JWT), qué ocurrió y cuándo.
     * `external_event_id` (idempotencyKey de JaaS) es único: los reintentos del
     * proveedor no duplican filas.
     */
    public function up(): void
    {
        Schema::create('lesson_presence_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('classes')->cascadeOnDelete();
            // NULL cuando el id del participante no corresponde al docente ni al
            // padre de la clase (no se fuerza una atribución dudosa).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 24); // participant_joined | participant_left
            $table->boolean('is_moderator')->default(false);
            $table->string('disconnect_reason', 32)->nullable();
            $table->string('session_id', 128)->nullable();
            $table->string('participant_ref', 128)->nullable();
            $table->string('external_event_id', 128)->unique();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['lesson_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_presence_events');
    }
};
