<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consentimiento explícito para notificaciones por WhatsApp.
 *
 * `WhatsAppChannel` solo comprobaba `phone_verified_at`. Verificar que alguien
 * controla un número NO es lo mismo que aceptar recibir notificaciones en él:
 * son dos hechos distintos, y MOVA debe poder demostrar por qué envía un
 * mensaje a una persona concreta.
 *
 * Dos marcas de tiempo en vez de un booleano: hay que poder responder «cuándo
 * lo aceptó» y «cuándo lo revocó», no solo el estado actual.
 *
 * SIN BACKFILL. Una versión anterior de esta migración hacía
 * `whatsapp_opt_in_at = phone_verified_at` para todos los usuarios existentes.
 * Eso era exactamente el error que esta migración existe para evitar: tratar
 * la verificación de un número (autenticación) como si fuera consentimiento
 * de marketing/notificaciones (una decisión de producto distinta). Verificar
 * un teléfono no es "sí, quiero que me escriban". Los usuarios existentes
 * quedan con `whatsapp_opt_in_at = NULL` — sin consentimiento — hasta que lo
 * den explícitamente vía `Auth\PhoneVerificationController::verify()` (con la
 * casilla de consentimiento) o `Profile\NotificationPreferencesForm`.
 *
 * CONSECUENCIA OPERATIVA CONOCIDA Y ACEPTADA: los usuarios que ya tenían el
 * teléfono verificado ANTES de este cambio dejan de recibir notificaciones
 * WhatsApp (recordatorios, confirmaciones) hasta que vuelvan a pasar por la
 * pantalla de preferencias y activen la casilla. Es un recorte de alcance
 * deliberado, no un bug: no existe forma de inferir consentimiento retroactivo
 * de forma honesta. `docs/MOVA_PHASE6_AUDIT.md` cuantifica cuántas cuentas
 * quedan afectadas en el momento del despliegue.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'whatsapp_opt_in_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('whatsapp_opt_in_at')->nullable()->after('phone_verified_at');
            $table->timestamp('whatsapp_opt_out_at')->nullable()->after('whatsapp_opt_in_at');
        });

        // Deliberadamente SIN backfill. Ver docblock de arriba.
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'whatsapp_opt_in_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_opt_in_at', 'whatsapp_opt_out_at']);
        });
    }
};
