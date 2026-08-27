<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-22 (auditoría de producción): la decisión previa de reutilizar `error`
 * (texto libre) para explicar un skip se documentó explícitamente como
 * válida solo "mientras exista un solo motivo de skip auditado". F-22
 * introdujo un segundo motivo real (cuenta suspendida) — la condición dejó
 * de cumplirse. Columna simple (no un enum a nivel de BD, a diferencia de
 * `status`): baja cardinalidad, sin necesidad todavía del patrón de
 * ampliación dual-driver que usan las migraciones de enum de este proyecto,
 * y validada en la capa de aplicación por App\WhatsApp\WhatsAppSkipReason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->string('skip_reason', 32)->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn('skip_reason');
        });
    }
};
