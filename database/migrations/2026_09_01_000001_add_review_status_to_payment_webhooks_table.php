<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Añade 'review' al enum de payment_webhooks.status — necesario para la
// integración real de Mercado Pago (Orders API), que puede reportar un
// reembolso PARCIAL (processed/partially_refunded) o un contracargo en
// estado ambiguo (charged_back/in_process) para los que MOVA
// deliberadamente NO ejecuta ninguna acción financiera automática (ver
// RechargeApprovalService::reverse() — nunca se llama para estos casos).
// 'review' señala "evento auténtico, ya persistido, pero requiere que un
// humano decida" — a diferencia de 'processed' (MOVA ya tomó la acción
// correcta) o 'failed' (error al procesar). No toca los 3 valores
// existentes ni ninguna fila actual.
//
// Mismo patrón que 2026_08_23_000001_add_reversal_type_to_credit_transactions.php:
// sin Blueprint::change() porque Doctrine DBAL no introspecta "enum" en
// SQLite (donde corren los tests, ver phpunit.xml) — ALTER MODIFY directo
// en MySQL, reconstrucción de columna en SQLite.
return new class extends Migration
{
    private const OLD_STATUSES = ['received', 'processed', 'failed'];

    private const NEW_STATUSES = ['received', 'processed', 'failed', 'review'];

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE `payment_webhooks` MODIFY `status` ENUM('received','processed','failed','review') NOT NULL DEFAULT 'received'"
            );

            return;
        }

        $this->rebuildStatusColumn(self::NEW_STATUSES);
    }

    public function down(): void
    {
        $this->assertNoReviewRowsExist();

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE `payment_webhooks` MODIFY `status` ENUM('received','processed','failed') NOT NULL DEFAULT 'received'"
            );

            return;
        }

        $this->rebuildStatusColumn(self::OLD_STATUSES);
    }

    /**
     * Reconstruye payment_webhooks.status con un CHECK constraint nuevo,
     * sin pasar por Blueprint::change(). Preserva el índice simple
     * ['status'] y el default 'received'.
     */
    private function rebuildStatusColumn(array $allowedStatuses): void
    {
        SchemaBuilder::useNativeSchemaOperationsIfPossible();

        Schema::table('payment_webhooks', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('payment_webhooks', function (Blueprint $table) use ($allowedStatuses) {
            $table->enum('status_tmp', $allowedStatuses)->default('received')->after('status');
        });

        DB::statement('UPDATE payment_webhooks SET status_tmp = status');

        Schema::table('payment_webhooks', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('payment_webhooks', function (Blueprint $table) {
            $table->renameColumn('status_tmp', 'status');
        });

        Schema::table('payment_webhooks', function (Blueprint $table) {
            $table->index('status');
        });
    }

    private function assertNoReviewRowsExist(): void
    {
        if (DB::table('payment_webhooks')->where('status', 'review')->exists()) {
            throw new RuntimeException(
                'Rollback abortado: existen webhooks en estado "review"; '
                .'quitar el valor del enum destruiría un registro que un humano todavía no resolvió.'
            );
        }
    }
};
