<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F-09 — Elimina el estado muerto `in_progress` del enum de `classes.status`.
 *
 * Búsqueda global previa: NINGÚN controlador, servicio, comando, job, listener
 * ni modelo asigna jamás `in_progress`. La máquina de estados real salta de
 * 'scheduled' directamente a 'paid'. El valor solo existía en:
 *   - la definición del enum (2024_01_01_000008 y las dos que lo ampliaron),
 *   - resources/js/utils/statusColors.js, con etiqueta "En curso" y badge
 *     amarillo: una rama de UI que nunca se renderizaba,
 *   - un data provider de RescheduleTest que verifica que NO se pueda
 *     reprogramar desde ese estado.
 *
 * Ya había sido señalado como LOW en docs/FINAL_PRODUCTION_AUDIT.md y seguía
 * presente. Un enum que admite un valor que ningún código produce, más una UI
 * viva para él, hace creer a quien llega nuevo que MOVA rastrea clases en
 * tiempo real. No lo hace.
 *
 * Solo MySQL: en SQLite la columna `status` ya quedó como string plano tras
 * 2026_07_17_000001 (verificado en su momento inspeccionando sqlite_master),
 * así que no hay enum que recortar allí.
 */
return new class extends Migration
{
    private const STATUSES_AFTER = "'scheduled', 'paid', 'pending_parent_confirmation', 'completed', 'cancelled', 'needs_admin_review'";

    private const STATUSES_BEFORE = "'scheduled', 'in_progress', 'paid', 'pending_parent_confirmation', 'completed', 'cancelled', 'needs_admin_review'";

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Guard: si alguna fila usa el estado, abortar antes de tocar el
        // esquema. MySQL convertiría esos valores en '' silenciosamente, que
        // es precisamente el tipo de reescritura de datos que las migraciones
        // financieras de este repositorio ya bloquean por norma.
        $inProgress = DB::table('classes')->where('status', 'in_progress')->count();

        if ($inProgress > 0) {
            throw new RuntimeException(
                "Hay {$inProgress} clase(s) en estado 'in_progress'. Eliminar el valor del enum las "
                .'convertiría en cadena vacía. Resuelve esas clases antes de ejecutar esta migración.'
            );
        }

        DB::statement("ALTER TABLE classes MODIFY status ENUM(".self::STATUSES_AFTER.") NOT NULL DEFAULT 'scheduled'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE classes MODIFY status ENUM(".self::STATUSES_BEFORE.") NOT NULL DEFAULT 'scheduled'");
    }
};
