<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P1 — reliability/environment parity, mismo linaje de raíz que el P0 de
// 2026_08_27_000002_widen_class_requests_status_enum_for_sqlite.php:
// paridad de constraints SQLite/MySQL para columnas de estado, en la
// dirección OPUESTA. Allá SQLite era demasiado estricto (rechazaba un
// valor que MySQL sí aceptaba). Aquí SQLite es demasiado laxo: desde
// 2026_07_17_000001_add_payment_states_to_classes_table.php, `classes.status`
// en SQLite es un VARCHAR(255) libre — sin CHECK, sin enum — mientras
// MySQL mantiene un ENUM real. Verificado en vivo, no asumido: fresh
// migrate contra sqlite::memory: y lectura de sqlite_master arroja
// exactamente `status VARCHAR(255) DEFAULT 'scheduled' NOT NULL`.
//
// Auditoría transversal completa hecha antes de escribir esta migración
// (no se asumió que el enum documentado es el usado realmente):
// se rastreó cada escritor real de `classes.status` en el código —
// LessonController::store()/confirmPayment()/cancel() (scheduled, paid,
// cancelled), LessonReportController::store() (pending_parent_confirmation),
// LessonSettlementService::consume()/refund() (completed, cancelled),
// SettleLessons::escalateToReview() (needs_admin_review),
// AdminController::cancelLesson() (cancelled) — los 6 valores del enum
// tienen escritor real y correcto; ninguno escribe un valor fuera de este
// conjunto. `in_progress` ya no existe en ningún escritor (limpiado del
// ENUM de MySQL en 2026_08_24_000002_remove_in_progress_from_classes_status_enum.php)
// y no se reintroduce aquí. Cada transición ya está guardada en código con
// `abort_unless($lesson->status === '<estado previo esperado>', 422, ...)`
// bajo `lockForUpdate()` — el riesgo que esta migración cierra es la
// AUSENCIA de una segunda capa de defensa a nivel de base de datos en
// SQLite (la única que existe hoy son esos guards de aplicación), no una
// escritura inválida ya ocurrida. No es un incidente de producción
// confirmado — es un riesgo de paridad de entornos: un typo futuro en
// cualquiera de esos 6 escritores pasaría en silencio toda la suite
// (100% SQLite) y solo se descubriría contra MySQL real.
//
// Preserva los TRES índices reales que tocan `status` (verificado
// leyendo sqlite_master de una migración fresca, no solo el código
// fuente): `classes_status_index` (2026_08_01_212524),
// `classes_status_settled_index` compuesto con `credits_settled_at`
// (2026_08_16_000001), y `classes_teacher_status_start_index` compuesto
// con `teacher_profile_id`/`start_time` (2026_08_22_000001). Ninguno
// puede coexistir con un DROP de la columna `status` sin recrearse
// después — a diferencia del rebuild de `class_requests.status`, que
// solo tenía un índice simple.
return new class extends Migration
{
    private const CANONICAL_STATUSES = [
        'scheduled', 'paid', 'pending_parent_confirmation',
        'completed', 'cancelled', 'needs_admin_review',
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // No-op solo si la invariante que asume es real: el ENUM de
            // MySQL ya coincide con el contrato canónico de arriba desde
            // 2026_08_24_000002. No se asume en silencio.
            $this->assertMysqlEnumMatchesCanonicalStatuses();

            return;
        }

        $this->dropStatusIndexes();

        Schema::table('classes', function (Blueprint $table) {
            $table->enum('status_tmp', self::CANONICAL_STATUSES)->default('scheduled')->after('status');
        });

        DB::statement('UPDATE classes SET status_tmp = status');

        Schema::table('classes', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('classes', function (Blueprint $table) {
            $table->renameColumn('status_tmp', 'status');
        });

        $this->restoreStatusIndexes();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // No-op simétrico: esta migración nunca tocó el ENUM de MySQL,
            // solo verificó que ya era correcto.
            return;
        }

        // A diferencia de 2026_08_27_000002 (revertir ahí podía dejar filas
        // en un valor que ya no sería válido), revertir esto siempre es
        // seguro: pasar de un CHECK estricto a un VARCHAR libre nunca puede
        // invalidar una fila existente — un string libre acepta un
        // superconjunto estricto de lo que el enum aceptaba. No hace falta
        // guard de datos.
        $this->dropStatusIndexes();

        Schema::table('classes', function (Blueprint $table) {
            $table->string('status_tmp')->default('scheduled')->after('status');
        });

        DB::statement('UPDATE classes SET status_tmp = status');

        Schema::table('classes', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('classes', function (Blueprint $table) {
            $table->renameColumn('status_tmp', 'status');
        });

        $this->restoreStatusIndexes();
    }

    private function dropStatusIndexes(): void
    {
        SchemaBuilder::useNativeSchemaOperationsIfPossible();

        Schema::table('classes', function (Blueprint $table) {
            $table->dropIndex('classes_status_index');
            $table->dropIndex('classes_status_settled_index');
            $table->dropIndex('classes_teacher_status_start_index');
        });
    }

    private function restoreStatusIndexes(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->index('status', 'classes_status_index');
            $table->index(['status', 'credits_settled_at'], 'classes_status_settled_index');
            $table->index(['teacher_profile_id', 'status', 'start_time'], 'classes_teacher_status_start_index');
        });
    }

    /**
     * Mismo patrón que
     * 2026_08_27_000002_widen_class_requests_status_enum_for_sqlite.php::
     * assertMysqlEnumAlreadyIncludesTeacherRejected() — un no-op de MySQL
     * que resulta estar equivocado es indistinguible de un éxito hasta que
     * algo falla en producción. Se compara el conjunto exacto de valores
     * del ENUM real contra el contrato canónico — ni de más ni de menos,
     * para detectar tanto un estado canónico ausente como un estado
     * histórico (p. ej. `in_progress`) que debería haberse limpiado y no
     * se limpió en esta instalación.
     */
    private function assertMysqlEnumMatchesCanonicalStatuses(): void
    {
        $column = DB::selectOne("SHOW COLUMNS FROM classes LIKE 'status'");

        if (! $column) {
            throw new RuntimeException(
                'Drift de schema detectado: no se pudo leer la columna classes.status en MySQL.'
            );
        }

        preg_match("/^enum\((.*)\)$/i", $column->Type, $matches);
        $actual = collect(explode(',', $matches[1] ?? ''))
            ->map(fn ($value) => trim($value, " '"))
            ->filter()
            ->sort()
            ->values()
            ->all();

        $expected = collect(self::CANONICAL_STATUSES)->sort()->values()->all();

        if ($actual !== $expected) {
            throw new RuntimeException(
                'Drift de schema detectado: el ENUM real de classes.status ('
                .implode(', ', $actual).') no coincide con el contrato canónico ('
                .implode(', ', $expected).'). No continuar en silencio — revisar '
                .'el historial de migraciones de esta instalación antes de reintentar.'
            );
        }
    }
};
