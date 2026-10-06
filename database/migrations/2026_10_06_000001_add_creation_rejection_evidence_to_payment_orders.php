<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidencia PERSISTENTE de la respuesta de Mercado Pago a `POST /v1/payments` cuando NO fue exitosa.
 *
 * Incidente 2026-10-06 (recarga 1): MP rechazó la creación (HTTP 400, «Invalid value for transaction_amount») pero esa
 * respuesta solo quedó en el log: la reconciliación posterior (`markReview`) sobrescribe `provider_status`, y sin la
 * respuesta original ningún proceso puede demostrar después que el rechazo fue terminal. Aditiva (todas nullable).
 * Solo escalares cortos: nunca el body, mensajes libres ni datos del pagador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('creation_http_status')->nullable()->after('provider_status_detail');
            $table->string('creation_error_codes', 191)->nullable()->after('creation_http_status');
            $table->string('creation_provider_request_id', 100)->nullable()->after('creation_error_codes');
            $table->timestamp('creation_failed_at')->nullable()->after('creation_provider_request_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->dropColumn(['creation_http_status', 'creation_error_codes', 'creation_provider_request_id', 'creation_failed_at']);
        });
    }
};
