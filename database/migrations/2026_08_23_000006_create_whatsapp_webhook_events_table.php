<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Deduplicación a nivel de EVENTO de webhook — distinta y complementaria a
// la máquina de estados de whatsapp_messages (WhatsAppMessageStatus::
// deliveryRank()). La máquina de estados ya garantiza que aplicar el MISMO
// evento dos veces es un no-op (rank igual no avanza nada) — esto añade
// una segunda capa, a nivel de base de datos, que ni siquiera intenta
// reevaluar un evento que Meta ya entregó antes (reintento de la entrega
// del webhook en sí, no solo del contenido). Mismo principio que
// payment_webhooks para Culqi: la app decide, pero la BD también protege.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_webhook_events', function (Blueprint $table) {
            $table->id();
            // sha256(provider_message_id + status + timestamp) — Meta no
            // expone un id de evento propio en los webhooks de estado (solo
            // wamid + status + timestamp), así que este hash ES el
            // identificador del evento en la práctica.
            $table->string('event_key', 64)->unique();
            $table->string('provider_message_id', 191);
            $table->string('status', 20);
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_webhook_events');
    }
};
