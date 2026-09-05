<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incidencias operativas que exigen decisión humana.
 *
 * POR QUÉ UNA TABLA Y NO LA CACHÉ:
 *
 * La deduplicación necesita un estado compartido entre tres procesos distintos
 * (mova-web, mova-queue, mova-scheduler). El cache store por defecto del repo es
 * `file`, local a cada contenedor — el propio `mova:health-check` avisa de ello
 * al comprobar el lock de `withoutOverlapping()`. Deduplicar por caché habría
 * significado que cada proceso alertara por su cuenta.
 *
 * Además, la tabla es el sustrato que el futuro Centro de Operaciones necesita:
 * un admin tiene que poder responder "¿qué está roto ahora mismo?" sin grepear
 * logs. Esta fase NO construye esa pantalla — solo deja los eventos observables
 * y consultables, como pide el encargo.
 *
 * NO es un log: `alert_key` es UNIQUE a propósito. Una misma incidencia (el
 * mismo PaymentOrder en revisión, la misma clase varada) es UNA fila cuyo
 * contador sube, no N filas. Esa es la garantía anti-spam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_alerts', function (Blueprint $table) {
            $table->id();

            // Identidad estable de la incidencia, no del evento. Ej:
            // "payment_order:42:review", "lesson:87:needs_admin_review",
            // "health:SETTLEMENT_DRY_RUN_IN_PRODUCTION".
            $table->string('alert_key', 191)->unique();

            $table->string('type', 64);
            $table->string('severity', 16)->default('warning'); // warning | critical

            $table->string('title', 200);
            $table->text('message');
            $table->json('context')->nullable();

            // `dateTime`, NO `timestamp`, y la diferencia importa:
            //
            // MySQL/MariaDB solo concede el `DEFAULT CURRENT_TIMESTAMP`
            // implícito a la PRIMERA columna TIMESTAMP NOT NULL de una tabla.
            // La segunda, sin default explícito, es un error de esquema
            // ("Invalid default value for 'last_detected_at'"). SQLite acepta
            // ambas sin rechistar, así que la suite de tests —que corre sobre
            // SQLite— nunca lo habría detectado: se descubrió al migrar contra
            // la base MySQL de QA.
            //
            // `dateTime` no tiene ese comportamiento especial en ningún motor y
            // expresa mejor la intención: son marcas que escribe la aplicación,
            // no valores que la base de datos deba rellenar sola.
            $table->dateTime('first_detected_at');
            $table->dateTime('last_detected_at');
            $table->unsignedInteger('occurrences')->default(1);

            // Cuándo se avisó a los admins. NULL = pendiente de aviso. Es la
            // guarda que impide re-notificar en cada barrido.
            $table->timestamp('notified_at')->nullable();

            // Cuándo dejó de estar activa. Una incidencia resuelta que vuelve a
            // aparecer SÍ debe volver a avisar: por eso resolver limpia
            // notified_at (ver OperationalAlertService::resolve()).
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            // "Dame lo que está abierto, lo más reciente primero" — la consulta
            // que hará el panel de admin.
            $table->index(['resolved_at', 'severity', 'last_detected_at'], 'operational_alerts_open_index');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_alerts');
    }
};
