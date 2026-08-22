<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deuda técnica identificada en la auditoría de cierre de C-1/C-2/C-3.
 *
 * LessonController::hasScheduleOverlap() —el chequeo que corre en cada
 * store()/reschedule(), dentro de una transacción con lockForUpdate()—
 * filtra por teacher_profile_id (igualdad), status (igualdad) y start_time
 * (rango). Hoy solo existen índices de una sola columna sobre cada uno
 * (teacher_profile_id vía la FK, status y start_time vía
 * 2026_08_01_212524_add_missing_indexes_to_classes_and_class_requests) —
 * MySQL solo puede aprovechar uno de los tres a la vez (o hacer index
 * merge, más caro que un solo índice compuesto). El orden de columnas
 * importa: igualdad, igualdad, rango — así el motor puede resolver las dos
 * primeras condiciones y solo recorrer el rango final de start_time.
 *
 * Bajo carga, un escaneo más amplio de lo necesario sobre esta consulta
 * significa sostener el lock más tiempo del necesario — exactamente el
 * tipo de ventana que agrava el deadlock reschedule×reschedule ya
 * documentado en C-2 (preexistente, no introducido aquí).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->index(['teacher_profile_id', 'status', 'start_time'], 'classes_teacher_status_start_index');
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropIndex('classes_teacher_status_start_index');
        });
    }
};
