<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Retry budget para MercadoPagoWebhookRecoveryService: sin este contador,
// un payment_webhooks que llega a 'failed' por una causa NO transitoria
// (no un MP_API_DOWN pasajero, sino p. ej. un bug real o un dato
// permanentemente inconsistente) se resetearía a 'received' y se
// reencolaría en CADA barrido del recovery, para siempre, sin que ningún
// humano se entere — un loop infinito silencioso. recovery_attempts cuenta
// cuántas veces el recovery (no el propio job — $tries de
// ProcessMercadoPagoWebhook es un contador DISTINTO, de reintentos dentro
// de UN dispatch) ya reseteó este webhook; al superar el tope configurado
// (config('payments.mercadopago.recovery.max_recovery_attempts')), el
// recovery deja de reencolar y lo deja en 'review' para revisión humana.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_webhooks', function (Blueprint $table) {
            $table->unsignedInteger('recovery_attempts')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('payment_webhooks', function (Blueprint $table) {
            $table->dropColumn('recovery_attempts');
        });
    }
};
