<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-07 — Elimina `classes.jitsi_password`, un secreto muerto.
 *
 * La columna se creó en 2026_07_22_000001 cuando MOVA usaba meet.jit.si, donde
 * la sala se protegía con contraseña. Al migrar a JaaS (8x8.vc) la
 * autenticación pasó a ser un JWT firmado con RS256 que lleva el `room` en el
 * payload, y la contraseña dejó de tener función.
 *
 * Búsqueda global previa a esta migración: `jitsi_password` aparecía solo en
 * el punto de generación (LessonController), en $fillable/$hidden del modelo,
 * en esta pareja de migraciones, y en tests que verifican que NO se filtre.
 * En ningún sitio se leía para usarlo — LessonController::join() devuelve
 * jitsi_room + jitsi_token + jaas_app_id, nunca la contraseña.
 *
 * Es decir: un secreto en texto plano en una tabla de producción que no
 * protegía nada, ampliando la superficie de un volcado de base de datos sin
 * aportar seguridad.
 *
 * El `down()` recrea la columna vacía, no los valores: eran aleatorios y sin
 * uso, así que no hay historial que preservar. Esto es deliberado y es la
 * diferencia con las migraciones financieras, cuyo rollback aborta antes de
 * destruir datos con significado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('classes', 'jitsi_password')) {
            return;
        }

        Schema::table('classes', function (Blueprint $table) {
            $table->dropColumn('jitsi_password');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('classes', 'jitsi_password')) {
            return;
        }

        Schema::table('classes', function (Blueprint $table) {
            $table->string('jitsi_password')->nullable()->after('jitsi_room');
        });
    }
};
