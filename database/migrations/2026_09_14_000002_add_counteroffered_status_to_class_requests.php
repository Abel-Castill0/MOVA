<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Añade 'counteroffered' al ENUM de class_requests.status.
 *
 * PATRÓN DUAL MySQL / SQLite — igual al de:
 *   2026_09_04_000002_add_expired_status_to_class_requests.php
 *   2026_08_27_000002_widen_class_requests_status_enum_for_sqlite.php
 *   2026_08_23_000002_add_reversal_state_to_recharge_requests.php
 *
 * Doctrine DBAL no sabe introspeccionar "enum" en SQLite, así que
 * Blueprint::change() no sirve para ninguno de los dos drivers. MySQL usa
 * ALTER MODIFY; SQLite reconstruye la columna a mano preservando todos los
 * datos existentes.
 *
 * Ciclo de vida del nuevo status:
 *   open → (profesor propone hora) → counteroffered
 *   counteroffered → (padre dice SÍ)  → accepted
 *   counteroffered → (padre dice NO)  → open      (vuelve al marketplace)
 */
return new class extends Migration
{
    private const OLD_STATUSES = [
        'pending_parent_approval', 'open', 'accepted',
        'rejected', 'teacher_rejected', 'completed', 'expired',
    ];

    private const NEW_STATUSES = [
        'pending_parent_approval', 'open', 'counteroffered', 'accepted',
        'rejected', 'teacher_rejected', 'completed', 'expired',
    ];

    public function up(): void
    {
        $this->applyStatuses(self::NEW_STATUSES);
    }

    public function down(): void
    {
        // Ninguna fila debería estar en 'counteroffered' al hacer rollback,
        // pero si las hay se devuelven a 'open' — es el estado de origen
        // correcto y el único desde el que se llega a 'counteroffered'.
        if (DB::table('class_requests')->where('status', 'counteroffered')->exists()) {
            DB::table('class_requests')
                ->where('status', 'counteroffered')
                ->update(['status' => 'open']);
        }

        $this->applyStatuses(self::OLD_STATUSES);
    }

    private function applyStatuses(array $statuses): void
    {
        if (DB::getDriverName() === 'mysql') {
            $list = implode(', ', array_map(fn ($s) => "'$s'", $statuses));
            DB::statement("ALTER TABLE class_requests MODIFY status ENUM($list) NOT NULL DEFAULT 'open'");

            return;
        }

        // SQLite: reconstrucción de la columna manteniendo todos los datos.
        $this->rebuildStatusColumn($statuses);
    }

    private function rebuildStatusColumn(array $allowedStatuses): void
    {
        SchemaBuilder::useNativeSchemaOperationsIfPossible();

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
};
