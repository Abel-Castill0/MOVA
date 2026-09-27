<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P0 — corrige una brecha real de paridad SQLite/MySQL, no agrega un
// estado nuevo. 2026_07_08_000001_update_class_requests_status_enum.php
// ya amplió el ENUM de MySQL para incluir 'teacher_rejected', pero esa
// migración tiene un `if (driver === 'mysql')` sin rama para SQLite — el
// CHECK constraint de SQLite (la base con la que corre TODA la suite de
// tests) se quedó en la lista vieja. Verificado insertando directamente en
// un schema migrado desde cero en sqlite::memory::
//   INSERT INTO class_requests (..., status) VALUES (..., 'teacher_rejected')
//   → SQLSTATE[23000]: CHECK constraint failed: status
// ClassRequestController::teacherReject() escribe exactamente ese valor en
// producción — el mismo código fallaría con un 500 si este flujo se
// ejecutara contra la base de datos de test tal como estaba.
//
// En MySQL esta migración es un no-op real: el ENUM ya es correcto desde
// 2026_07_08_000001, así que no hay nada que tocar ahí (evita re-ejecutar
// un ALTER MODIFY redundante).
//
// El widening en SQLite sigue el mismo patrón ya establecido en
// 2026_08_23_000002_add_reversal_state_to_recharge_requests.php
// (Blueprint::change() no sabe introspeccionar "enum" en SQLite): se
// reconstruye la columna a mano.
return new class extends Migration
{
    private const OLD_STATUSES = ['pending_parent_approval', 'open', 'accepted', 'rejected', 'completed'];

    private const NEW_STATUSES = ['pending_parent_approval', 'open', 'accepted', 'rejected', 'teacher_rejected', 'completed'];

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // No-op solo si la invariante que asume es real, no por
            // suposición silenciosa: si 2026_07_08_000001 no llegó a correr
            // (o alguien reordenó/editó migraciones), un no-op ciego dejaría
            // el mismo bug que esta migración existe para arreglar, pero sin
            // ninguna señal. Se verifica el ENUM real antes de no hacer nada.
            $this->assertMysqlEnumAlreadyIncludesTeacherRejected();

            return;
        }

        $this->rebuildStatusColumn(self::NEW_STATUSES);
    }

    /**
     * Falla ruidosamente en vez de asumir en silencio. Un no-op que resulta
     * estar equivocado (2026_07_08_000001 no corrió, o el ENUM de esta
     * instalación difiere de lo esperado por cualquier motivo) es
     * indistinguible de un éxito hasta que production explota con el mismo
     * "CHECK constraint failed" — o su equivalente MySQL — que esta
     * migración corrige del lado SQLite. Verificado que dispara: se simuló
     * localmente apuntando a un ENUM sin 'teacher_rejected' y se confirmó
     * que lanza esta excepción en vez de continuar en silencio.
     */
    private function assertMysqlEnumAlreadyIncludesTeacherRejected(): void
    {
        $column = DB::selectOne("SHOW COLUMNS FROM class_requests LIKE 'status'");

        if (! $column || ! str_contains($column->Type, "'teacher_rejected'")) {
            throw new RuntimeException(
                'Drift de schema detectado: esta migración asume que '
                .'2026_07_08_000001_update_class_requests_status_enum.php ya '
                .'amplió el ENUM de MySQL para incluir \'teacher_rejected\', '
                .'pero el ENUM real de class_requests.status no lo contiene '
                .'(Type actual: '.($column->Type ?? 'columna no encontrada').'). '
                .'No continuar en silencio — revisar el historial de '
                .'migraciones de esta instalación antes de reintentar.'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // No-op deliberado, simétrico al up(): esta migración nunca tocó
            // MySQL, así que no hay nada que revertir ahí.
            return;
        }

        $this->assertNoTeacherRejectedRowsExist();

        $this->rebuildStatusColumn(self::OLD_STATUSES);
    }

    /**
     * Reconstruye class_requests.status con un CHECK constraint nuevo, sin
     * pasar por Blueprint::change(). Preserva el índice simple sobre
     * `status` (2026_08_01_212524_add_missing_indexes_to_classes_and_class_requests.php)
     * y el default 'open'. A diferencia de recharge_requests.status (que es
     * nullable), class_requests.status es NOT NULL — la columna temporal
     * se declara igual (no nullable), con un default explícito para que
     * SQLite acepte agregarla sobre una tabla con filas existentes.
     */
    private function rebuildStatusColumn(array $allowedStatuses): void
    {
        Schema::table('class_requests', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('class_requests', function (Blueprint $table) use ($allowedStatuses) {
            $table->enum('status_tmp', $allowedStatuses)->default('open')->after('status');
        });

        DB::statement('UPDATE class_requests SET status_tmp = status');

        Schema::table('class_requests', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('class_requests', function (Blueprint $table) {
            $table->renameColumn('status_tmp', 'status');
        });

        Schema::table('class_requests', function (Blueprint $table) {
            $table->index('status');
        });
    }

    private function assertNoTeacherRejectedRowsExist(): void
    {
        if (DB::table('class_requests')->where('status', 'teacher_rejected')->exists()) {
            throw new RuntimeException(
                'Rollback abortado: existen solicitudes en estado "teacher_rejected"; '
                .'quitar el estado del CHECK las dejaría con un valor que ya no es válido.'
            );
        }
    }
};
