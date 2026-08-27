<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Log crudo de cada evento de webhook recibido de un proveedor de pago.
// La restricción UNIQUE(provider, event_id) es la garantía de idempotencia
// a nivel de base de datos contra reintentos/duplicados del proveedor —
// mismo principio que credit_transactions.idempotency_key: nunca confiar
// solo en un chequeo de código antes de escribir.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('event_id', 191);
            $table->string('event_type', 64);
            $table->json('payload');
            $table->string('payload_hash', 64);
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->enum('status', ['received', 'processed', 'failed'])->default('received');
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhooks');
    }
};
