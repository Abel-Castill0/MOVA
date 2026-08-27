<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Añade 'skipped' al enum de whatsapp_messages.status.
 *
 * Antes, cuando WhatsAppChannel decidía no enviar por falta de consentimiento
 * (o teléfono no verificado), no quedaba ninguna fila en whatsapp_messages —
 * solo un Log::debug. Eso mezclaba dos preguntas muy distintas bajo el mismo
 * silencio: "Meta rechazó el mensaje" (failed/unknown) y "MOVA decidió no
 * intentarlo" (ausencia total de registro). Sin poder distinguirlas, alguien
 * investigando "¿por qué este padre no recibió su recordatorio?" no tenía
 * ningún rastro que consultar.
 *
 * Mismo patrón dual que 2026_08_23_000001 (credit_transactions.type): en
 * SQLite (tests) Doctrine no sabe introspeccionar "enum" al alterar la
 * columna, así que se reconstruye a mano; en MySQL se usa ALTER MODIFY.
 */
return new class extends Migration
{
    private const OLD_STATUSES = ['sent', 'delivered', 'read', 'failed', 'unknown'];

    private const NEW_STATUSES = ['sent', 'delivered', 'read', 'failed', 'unknown', 'skipped'];

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE `whatsapp_messages` MODIFY `status` ENUM('sent','delivered','read','failed','unknown','skipped') NOT NULL DEFAULT 'sent'"
            );

            return;
        }

        $this->rebuildStatusColumn(self::NEW_STATUSES);
    }

    public function down(): void
    {
        $this->assertNoSkippedRowsExist();

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE `whatsapp_messages` MODIFY `status` ENUM('sent','delivered','read','failed','unknown') NOT NULL DEFAULT 'sent'"
            );

            return;
        }

        $this->rebuildStatusColumn(self::OLD_STATUSES);
    }

    private function rebuildStatusColumn(array $allowedStatuses): void
    {
        SchemaBuilder::useNativeSchemaOperationsIfPossible();

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('whatsapp_messages', function (Blueprint $table) use ($allowedStatuses) {
            $table->enum('status_tmp', $allowedStatuses)->nullable()->after('status');
        });

        DB::statement('UPDATE whatsapp_messages SET status_tmp = status');

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->renameColumn('status_tmp', 'status');
        });

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->index('status');
        });
    }

    private function assertNoSkippedRowsExist(): void
    {
        if (DB::table('whatsapp_messages')->where('status', 'skipped')->exists()) {
            throw new \RuntimeException(
                'Rollback abortado: existen filas "skipped" en whatsapp_messages; '
                .'quitar el estado del enum destruiría auditoría real de consentimiento.'
            );
        }
    }
};
