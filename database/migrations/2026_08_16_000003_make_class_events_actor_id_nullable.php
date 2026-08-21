<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C-1 Entrega 1 — permite eventos originados por el propio sistema.
 *
 * SEMÁNTICA (obligatoria, no dejar nulls sin significado):
 *
 *     class_events.actor_id = NULL  ⟺  el actor es MOVA (proceso automático)
 *     class_events.actor_id = <id>  ⟺  el actor es ese usuario
 *
 * Hasta ahora todo evento provenía de una acción humana con sesión iniciada,
 * así que la columna era NOT NULL. La liquidación automática de C-1 no tiene
 * usuario detrás y debe poder auditarse igual que cualquier otra operación.
 *
 * Alternativa descartada — crear un usuario "sistema": contaminaría la tabla
 * `users`, necesitaría un rol de Spatie, sería técnicamente autenticable y
 * obligaría a excluirlo de cada listado y contador de usuarios. Más
 * superficie que una columna nullable.
 *
 * Riesgo verificado antes de decidir: `ClassEvent` no define relación
 * `actor()`, no se consume en `resources/js`, no tiene scopes ni
 * serializadores. Los 5 puntos de escritura actuales pasan un usuario real.
 *
 * Técnica: SQL crudo en MySQL + ->change() en SQLite, el mismo patrón de
 * 2026_07_17_000001. Aquí la rama SQLite sí es necesaria (a diferencia del
 * enum): SQLite sí aplica NOT NULL sobre esta columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE class_events MODIFY actor_id BIGINT UNSIGNED NULL');

            return;
        }

        Schema::table('class_events', function (Blueprint $table) {
            $table->unsignedBigInteger('actor_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        $this->assertRollbackDoesNotDiscardSystemEvents();

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE class_events MODIFY actor_id BIGINT UNSIGNED NOT NULL');

            return;
        }

        Schema::table('class_events', function (Blueprint $table) {
            $table->unsignedBigInteger('actor_id')->nullable(false)->change();
        });
    }

    /**
     * Volver a NOT NULL con eventos de sistema presentes obligaría a
     * inventarles un actor o a borrarlos: ambas cosas destruyen auditoría.
     */
    private function assertRollbackDoesNotDiscardSystemEvents(): void
    {
        $systemEvents = DB::table('class_events')->whereNull('actor_id')->count();

        if ($systemEvents > 0) {
            throw new RuntimeException(
                "Rollback abortado: existen {$systemEvents} eventos de sistema (actor_id NULL). "
                .'Revertir la columna a NOT NULL destruiría ese registro de auditoría.'
            );
        }
    }
};
