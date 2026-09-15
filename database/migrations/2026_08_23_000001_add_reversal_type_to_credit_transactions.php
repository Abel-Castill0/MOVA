<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Añade 'reversal' al enum de credit_transactions.type — necesario para
// revertir una recarga YA aprobada (refund/chargeback del proveedor de
// pago), sin reutilizar 'refund' (que en LedgerReconciliation::
// derivedAvailableFor() SUMA al balance derivado; una reversión de depósito
// debe RESTAR, así que necesita su propio tipo con amount negativo — ver
// RechargeApprovalService::reverse()). No toca los 4 valores existentes ni
// ninguna fila actual.
//
// No se usa Blueprint::change() aquí: se escribió originalmente porque
// Doctrine DBAL (que Laravel 10 usaba para alterar columnas de forma
// portable) no sabía introspectar un tipo "enum" en SQLite ("Unknown column
// type \"enum\" requested"), y este proyecto corre los tests sobre sqlite
// :memory: (ver phpunit.xml). Laravel 11 quitó Doctrine DBAL por completo
// (Schema::useNativeSchemaOperationsIfPossible() ya no existe, ni hace
// falta: las operaciones nativas son ahora el único camino), pero se
// mantiene el mismo widening manual del CHECK constraint (columna temporal +
// backfill + drop + rename) para no tocar una migration financiera ya
// probada; en MySQL se sigue el mismo patrón de ALTER MODIFY ya usado en
// 2026_07_10_000001_harden_monetization_records.php.
return new class extends Migration
{
    private const OLD_TYPES = ['deposit', 'reservation', 'consumption', 'refund'];

    private const NEW_TYPES = ['deposit', 'reservation', 'consumption', 'refund', 'reversal'];

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE `credit_transactions` MODIFY `type` ENUM(\'deposit\',\'reservation\',\'consumption\',\'refund\',\'reversal\') NOT NULL'
            );

            return;
        }

        $this->rebuildTypeColumn(self::NEW_TYPES);
    }

    public function down(): void
    {
        $this->assertNoReversalRowsExist();

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE `credit_transactions` MODIFY `type` ENUM(\'deposit\',\'reservation\',\'consumption\',\'refund\') NOT NULL'
            );

            return;
        }

        $this->rebuildTypeColumn(self::OLD_TYPES);
    }

    /**
     * Reconstruye credit_transactions.type con un CHECK constraint nuevo
     * (vía enum() de Laravel, que en SQLite se traduce a
     * "varchar check (type in (...))") sin pasar por Blueprint::change().
     * Preserva el índice compuesto ['teacher_profile_id','type'].
     */
    private function rebuildTypeColumn(array $allowedTypes): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->dropIndex(['teacher_profile_id', 'type']);
        });

        Schema::table('credit_transactions', function (Blueprint $table) use ($allowedTypes) {
            $table->enum('type_tmp', $allowedTypes)->nullable()->after('type');
        });

        DB::statement('UPDATE credit_transactions SET type_tmp = type');

        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->dropColumn('type');
        });

        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->renameColumn('type_tmp', 'type');
        });

        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->index(['teacher_profile_id', 'type']);
        });
    }

    private function assertNoReversalRowsExist(): void
    {
        if (DB::table('credit_transactions')->where('type', 'reversal')->exists()) {
            throw new RuntimeException(
                'Rollback abortado: existen asientos "reversal" en el ledger; '
                .'quitar el tipo del enum destruiría historial financiero real.'
            );
        }
    }
};
