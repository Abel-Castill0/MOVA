<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Registro de auditoría de cada intento de envío por WhatsApp — no es una
// cola pre-envío (eso ya lo cubre el ShouldQueue de cada Notification vía
// Laravel; ver docs/whatsapp-architecture.md sobre por qué no se duplicó
// ese mecanismo con un "outbox" propio). Esto es el resultado DESPUÉS de
// intentar el envío: para soporte ("¿MOVA me mandó el mensaje?") y como
// base para que el webhook de Meta actualice el estado real de entrega
// (sent → delivered → read/failed) contra provider_message_id, respetando
// el orden real por timestamp (ver status_updated_at) y no solo el orden
// de llegada del webhook — Meta advierte explícitamente que los eventos
// pueden no llegar en el mismo orden en que ocurrieron.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->string('to', 32);
            $table->string('template_key', 64);
            // Identifica de dónde vino este envío para soporte — no es un id
            // de MOVA con significado propio en ningún otro sistema, solo
            // una etiqueta libre que arma el llamador (p. ej.
            // "ClassConfirmedNotification#482" o "phone_verification:91").
            //
            // NO es una clave de correlación: no tiene índice único ni
            // garantía de unicidad (dos envíos del mismo tipo al mismo
            // destinatario comparten el mismo valor). `provider_message_id`
            // es la ÚNICA clave de correlación real con un webhook de Meta —
            // nunca usar `client_reference` para intentar adivinar a qué
            // fila pertenece un evento entrante cuando falta el wamid (p.
            // ej. para "recuperar" una fila 'unknown'); eso arriesgaría
            // actualizar la fila equivocada si hay más de un envío con la
            // misma etiqueta.
            $table->string('client_reference', 191)->nullable();
            $table->string('provider', 32);
            // wamid de Meta — único cuando existe, null si el envío falló
            // antes de obtener uno (credenciales/plantilla faltante, error
            // de red, respuesta no exitosa).
            $table->string('provider_message_id', 191)->nullable();
            // sent: Meta ACEPTÓ el envío (200 + wamid) — no confirma que el
            // usuario lo haya recibido, eso llega después por webhook.
            // delivered/read: actualizados por el webhook cuando exista.
            // failed: Meta RECHAZÓ el envío de forma definitiva (respuesta
            // HTTP no exitosa) — un resultado conocido, no ambiguo.
            // unknown: el resultado es INCIERTO (timeout/excepción de red
            // antes de recibir respuesta) — Meta podría haber aceptado el
            // mensaje igual. Deliberadamente distinto de 'failed': no se
            // puede reintentar el envío sin riesgo de duplicar, pero tampoco
            // es correcto asumir que falló. mova:reconcile-whatsapp señala
            // estos para revisión manual.
            $table->enum('status', ['sent', 'delivered', 'read', 'failed', 'unknown'])->default('sent');
            $table->string('error', 500)->nullable();
            // Timestamp del ÚLTIMO evento de estado aplicado (según el
            // 'timestamp' que reporta Meta en el webhook, no el reloj de
            // MOVA) — es la base para no dejar que un evento fuera de orden
            // retroceda un estado más avanzado (sent < delivered < read).
            $table->timestamp('status_updated_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_message_id']);
            $table->index(['to', 'created_at']);
            $table->index('status');
            $table->index('client_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
