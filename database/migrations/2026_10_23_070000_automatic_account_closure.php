<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Cierre automático de cuenta (forward-only; down() es deliberadamente un no-op, igual que la
// migración de solicitudes): marca de identidad suprimida, estado de limpieza secundaria y resumen
// del tratamiento, más el asiento de ledger 'forfeiture' (importe NEGATIVO, como 'reversal') para el
// saldo no utilizado que el titular acepta perder. No toca filas existentes.
return new class extends Migration
{
    private const NEW_TYPES = ['deposit', 'reservation', 'consumption', 'refund', 'reversal', 'forfeiture'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('account_erased_at')->nullable()->after('account_closed_at');
        });

        // Fecha desde la que una solicitud devuelta a búsqueda (profesor cerró su cuenta) cuenta su plazo de expiración.
        Schema::table('class_requests', function (Blueprint $table) {
            $table->timestamp('republished_at')->nullable();
        });

        Schema::table('account_deletion_requests', function (Blueprint $table) {
            $table->timestamp('erased_at')->nullable();
            // not_required | pending | completed | failed — limpieza secundaria (archivos externos).
            $table->string('cleanup_status', 16)->default('not_required');
            $table->unsignedTinyInteger('cleanup_attempts')->default(0);
            $table->json('outcome')->nullable();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE `credit_transactions` MODIFY `type` ENUM(\'deposit\',\'reservation\',\'consumption\',\'refund\',\'reversal\',\'forfeiture\') NOT NULL'
            );

            return;
        }

        // SQLite (tests): mismo patrón de reconstrucción del CHECK que 2026_08_23_000001.
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->dropIndex(['teacher_profile_id', 'type']);
        });
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->enum('type_tmp', self::NEW_TYPES)->nullable()->after('type');
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

    public function down(): void
    {
        // Forward-only: no se destruye evidencia de cierres ni asientos de ledger.
    }
};
