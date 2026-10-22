<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Culqi Custom Checkout: datos mínimos para operar 3DS y distinguir el medio de pago.
//
//  - payment_channel:       'card' | 'yape' | 'order' (orden de pago: PagoEfectivo/billeteras/…).
//  - source_token_hash:     SHA-256 del token de Culqi del intento. La documentación de Culqi 3DS exige que el segundo
//                           POST /v2/charges use el MISMO source_id; se guarda el hash (nunca el token) para verificar
//                           que quien completa el 3DS es el mismo que inició el intento.
//  - device_fingerprint_id: UUID de Culqi3DS.generateDevice(), que también debe repetirse en el segundo cargo.
//
// Forward-only: columnas nulas, sin backfill. Compatible con MySQL y SQLite. No toca filas de Mercado Pago.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->string('payment_channel', 24)->nullable()->after('provider');
            $table->string('source_token_hash', 64)->nullable()->after('provider_order_id');
            $table->string('device_fingerprint_id', 64)->nullable()->after('source_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->dropColumn(['payment_channel', 'source_token_hash', 'device_fingerprint_id']);
        });
    }
};
