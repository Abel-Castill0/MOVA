<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C-1 Entrega 1 — añade `needs_admin_review` al enum de `classes.status`.
 *
 * Estado para clases que quedaron sin señal alguna (el padre nunca confirmó
 * el pago) y requieren decisión humana. Sus créditos siguen RESERVADOS: este
 * estado no tiene efecto financiero por sí mismo.
 *
 * Técnica: solo MySQL, siguiendo el patrón de
 * 2026_07_08_000001_update_class_requests_status_enum. En SQLite (motor de la
 * suite de tests) la columna quedó como string libre tras
 * 2026_07_17_000001 — verificado inspeccionando sqlite_master: no hay CHECK
 * sobre `status`. Por eso no hace falta rama SQLite, y así evitamos depender
 * de Doctrine DBAL para este cambio.
 *
 * El valor se añade al FINAL de la lista para no alterar el orden de los
 * existentes (MySQL almacena ENUM por índice ordinal; reordenar reescribiría
 * silenciosamente los datos).
 */
return new class extends Migration
{
    private const STATUSES_AFTER = "'scheduled', 'in_progress', 'paid', 'pending_parent_confirmation', 'completed', 'cancelled', 'needs_admin_review'";

    private const STATUSES_BEFORE = "'scheduled', 'in_progress', 'paid', 'pending_parent_confirmation', 'completed', 'cancelled'";

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            'ALTER TABLE classes MODIFY status ENUM('.self::STATUSES_AFTER.") NOT NULL DEFAULT 'scheduled'"
        );
    }

    public function down(): void
    {
        $this->assertRollbackDoesNotRewriteLessonStates();

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            'ALTER TABLE classes MODIFY status ENUM('.self::STATUSES_BEFORE.") NOT NULL DEFAULT 'scheduled'"
        );
    }

    /**
     * Deliberadamente NO se reescriben las clases en needs_admin_review a otro
     * estado. Ese es exactamente el antipatrón de
     * 2026_07_17_000001_add_payment_states_to_classes_table::down(), que
     * convierte 'paid' y 'pending_parent_confirmation' en 'scheduled' en
     * silencio y destruye la evidencia de que el pago ocurrió (hallazgo A-7
     * de la auditoría). Aquí se aborta y se exige remediación explícita.
     */
    private function assertRollbackDoesNotRewriteLessonStates(): void
    {
        $inReview = DB::table('classes')->where('status', 'needs_admin_review')->count();

        if ($inReview > 0) {
            throw new RuntimeException(
                "Rollback abortado: {$inReview} clases están en needs_admin_review y revertir "
                .'el enum las dejaría en un estado inválido o las reescribiría. Resuélvelas primero.'
            );
        }
    }
};
