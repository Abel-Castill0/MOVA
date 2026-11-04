<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial auditable (solo inserciones) de las altas y bajas de avisos por WhatsApp: cuándo, por qué vía, con qué versión
 * del texto y para qué finalidad. `users.whatsapp_opt_in_at` / `whatsapp_opt_out_at` siguen siendo el estado vigente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_consent_events')) {
            return;
        }

        Schema::create('whatsapp_consent_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // la evidencia sobrevive al borrado
            $table->string('action', 16);          // opt_in | opt_out | opt_in_reset
            $table->string('source', 32);          // registration | phone_verification | profile | whatsapp_keyword
            $table->string('text_version', 32)->nullable();
            $table->string('purpose', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_consent_events');
    }
};
