<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Añade el estado 'reversed' (recarga aprobada que luego se revierte por
// refund/chargeback del proveedor de pago) y sus columnas de auditoría —
// mismo patrón que reviewed_at/reviewed_by/approved_at/rejected_at ya
// existentes para approve/reject.
//
// El widening del enum de `status` se hace igual que en
// 2026_08_23_000001_add_reversal_type_to_credit_transactions.php: sin
// Blueprint::change() (Doctrine DBAL no sabe introspeccionar "enum" en
// SQLite, y los tests corren sobre sqlite :memory:), reconstruyendo la
// columna a mano en SQLite y con ALTER MODIFY directo en MySQL.
return new class extends Migration
{
    private const OLD_STATUSES = ['pending', 'approved', 'rejected'];

    private const NEW_STATUSES = ['pending', 'approved', 'rejected', 'reversed'];

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE `recharge_requests` MODIFY `status` ENUM('pending','approved','rejected','reversed') NOT NULL DEFAULT 'pending'"
            );
        } else {
            $this->rebuildStatusColumn(self::NEW_STATUSES);
        }

        Schema::table('recharge_requests', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('rejection_reason');
            $table->foreignId('reversed_by')->nullable()->after('reversed_at');
            $table->string('reversal_reason', 500)->nullable()->after('reversed_by');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('recharge_requests', function (Blueprint $table) {
                $table->foreign('reversed_by')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        $this->assertNoReversedRowsExist();

        if (DB::getDriverName() === 'mysql') {
            Schema::table('recharge_requests', function (Blueprint $table) {
                $table->dropForeign(['reversed_by']);
            });
        }

        Schema::table('recharge_requests', function (Blueprint $table) {
            $table->dropColumn(['reversed_at', 'reversed_by', 'reversal_reason']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE `recharge_requests` MODIFY `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'"
            );
        } else {
            $this->rebuildStatusColumn(self::OLD_STATUSES);
        }
    }

    /**
     * Reconstruye recharge_requests.status con un CHECK constraint nuevo
     * sin pasar por Blueprint::change(). Preserva el índice compuesto
     * ['teacher_profile_id','status'] y el default 'pending'.
     */
    private function rebuildStatusColumn(array $allowedStatuses): void
    {
        SchemaBuilder::useNativeSchemaOperationsIfPossible();

        Schema::table('recharge_requests', function (Blueprint $table) {
            $table->dropIndex(['teacher_profile_id', 'status']);
        });

        Schema::table('recharge_requests', function (Blueprint $table) use ($allowedStatuses) {
            $table->enum('status_tmp', $allowedStatuses)->default('pending')->nullable()->after('status');
        });

        DB::statement('UPDATE recharge_requests SET status_tmp = status');

        Schema::table('recharge_requests', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('recharge_requests', function (Blueprint $table) {
            $table->renameColumn('status_tmp', 'status');
        });

        Schema::table('recharge_requests', function (Blueprint $table) {
            $table->index(['teacher_profile_id', 'status']);
        });
    }

    private function assertNoReversedRowsExist(): void
    {
        if (DB::table('recharge_requests')->where('status', 'reversed')->exists()) {
            throw new RuntimeException(
                'Rollback abortado: existen recargas "reversed"; quitar el estado '
                .'y sus columnas de auditoría destruiría historial financiero real.'
            );
        }
    }
};
