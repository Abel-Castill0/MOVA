<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado DURABLE del canal WAHA. No puede vivir en la caché: docker/entrypoint.sh ejecuta `optimize:clear` (que vacía la caché
 * de base de datos) en cada arranque de cada contenedor, y con ella se perdería quién escribió primero al número, la fecha de
 * vinculación (calentamiento), las restricciones de WhatsApp, la alerta de vinculación perdida y el último respaldo.
 *
 * Aditiva: solo crea dos tablas nuevas; no toca ninguna existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Reentrante: MySQL no revierte DDL; si una ejecución falla a medias, repetirla no debe chocar con tablas ya creadas.
        if (! Schema::hasTable('waha_state')) {
            Schema::create('waha_state', function (Blueprint $table) {
                $table->string('key', 64)->primary();
                $table->text('value')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        // Quién escribió primero al número de MOVA. Se guarda un HMAC del teléfono (no el número) y la fecha: nunca el texto.
        if (! Schema::hasTable('waha_contacts')) {
            Schema::create('waha_contacts', function (Blueprint $table) {
                $table->char('phone_hash', 64)->primary();
                $table->timestamp('last_inbound_at')->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('waha_contacts');
        Schema::dropIfExists('waha_state');
    }
};
