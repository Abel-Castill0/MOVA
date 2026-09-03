<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Corrige un P0 real: antes la X-Idempotency-Key se DERIVABA en cada
// llamada como "mova-recharge-{id}-attempt-{n}" — nunca se persistía. Eso
// es frágil ante la semántica real de reintento/timeout que exige esta
// ronda: si el POST /v1/payments se pierde por timeout DESPUÉS de que
// Mercado Pago ya procesó el pago, un reintento debe reenviar EXACTAMENTE
// la misma key para que Mercado Pago la trate como el mismo intento (y
// devuelva la respuesta original, documentado oficialmente) — derivarla de
// nuevo cada vez funcionaba por coincidencia (era determinística), pero no
// dejaba ningún rastro local de "esta es la key que se envió", lo que
// impedía auditar/correlacionar sin recalcular. Ahora se genera UNA vez
// (UUID v4) al crear la fila y se persiste — nunca se regenera para el
// mismo intento.
//
// UNIQUE: dos PaymentOrder nunca deben compartir la misma key (protege
// contra un bug que reutilizara una key entre intentos distintos).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->after('attempt_number');
        });

        Schema::table('payment_orders', function (Blueprint $table) {
            $table->unique('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
