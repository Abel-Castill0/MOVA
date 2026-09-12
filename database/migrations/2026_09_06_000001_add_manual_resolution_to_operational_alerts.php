<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3A — trazabilidad de la resolución MANUAL de una incidencia.
 *
 * POR QUÉ HACE FALTA: hasta ahora `resolved_at` solo lo escribía el propio
 * sistema desde el choke point del recurso (LessonSettlementService cuando la
 * clase llega a estado terminal, MercadoPagoPaymentReconciliationService
 * cuando el pago se aplica, ReconcileLedger/HealthCheck cuando la condición
 * desaparece). Ahí no hace falta saber "quién": fue el propio dominio.
 *
 * El Centro de Operaciones añade un segundo camino —un admin que cierra a mano
 * una incidencia— y ese sí necesita responder a quién, cuándo y por qué. Sin
 * estas columnas, un cierre manual sería indistinguible de una resolución
 * automática, que es justo la ambigüedad que no queremos en una tabla usada
 * para decidir si algo necesita atención.
 *
 * `resolved_by` es nullable a propósito y NO tiene FK con cascade: una
 * resolución automática no tiene actor, y si un día se borra el usuario admin
 * la traza histórica debe sobrevivir al usuario. Se usa nullOnDelete por la
 * misma razón: preferimos perder el "quién" antes que perder la fila entera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operational_alerts', function (Blueprint $table) {
            // Columna y FK por separado, no con foreignId()->constrained().
            // La forma fluida NO llegó a crear la constraint en MySQL en este
            // esquema (se verificó en information_schema: la columna aparecía y
            // la FK no), y una restricción que se declara pero no existe es
            // peor que no declararla: da una garantía falsa. Esta forma se
            // comprueba después con una consulta a information_schema.
            $table->unsignedBigInteger('resolved_by')->nullable()->after('resolved_at');

            // Instantánea del nombre del admin en el momento del cierre.
            //
            // POR QUÉ DUPLICAR ALGO QUE YA ESTÁ EN `users`: un usuario SÍ puede
            // borrarse físicamente (ProfileController::destroy llama a
            // $user->delete() y User no usa SoftDeletes). Con la FK en
            // nullOnDelete, borrar la cuenta del admin dejaría un cierre sin
            // autor: la fila sobreviviría pero la traza —que es justo lo que da
            // valor al cierre manual— se perdería en silencio.
            //
            // Se guarda solo el nombre, no el correo: basta para responder
            // "quién" en una auditoría y no propaga un dato de contacto a una
            // tabla operativa.
            $table->string('resolved_by_name', 120)->nullable()->after('resolved_by');

            // Motivo obligatorio en el flujo manual (lo exige la validación),
            // pero nullable en el esquema: las resoluciones automáticas, que
            // son la mayoría, no tienen ni necesitan uno.
            $table->string('resolution_note', 500)->nullable()->after('resolved_by_name');
        });

        Schema::table('operational_alerts', function (Blueprint $table) {
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('operational_alerts', function (Blueprint $table) {
            $table->dropForeign(['resolved_by']);
            $table->dropColumn(['resolved_by', 'resolved_by_name', 'resolution_note']);
        });
    }
};
