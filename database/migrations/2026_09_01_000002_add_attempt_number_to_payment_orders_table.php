<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Pivot Mercado Pago Orders API → Payments API (ver docs de la sesión):
// habilita múltiples intentos de pago por RechargeRequest (tarjeta
// rechazada → reintento con Yape, sin perder trazabilidad ni duplicar
// créditos). Antes payment_orders.recharge_request_id era UNIQUE (1:1) —
// eso bloqueaba con una violación de UNIQUE cualquier segundo intento tras
// un primer intento fallido, contradiciendo el requisito de producto
// "intento 1 rechazado → intento 2 aprobado" sobre la MISMA RechargeRequest.
//
// El ledger (credit_transactions.idempotency_key = "recharge:{id}:deposit",
// ver RechargeApprovalService::credit()) YA es exactamente-once por
// RechargeRequest sin importar cuántas filas payment_orders existan — este
// cambio solo toca payment_orders, nada del ledger.
//
// attempt_number es NUEVO: 1 en el primer intento, incrementado por cada
// intento legítimo posterior a un estado terminal negativo (failed/
// cancelled/expired) del intento anterior — nunca mientras uno sigue
// pending/created. La combinación (recharge_request_id, attempt_number)
// reemplaza al UNIQUE(recharge_request_id) simple como la garantía de "no
// dos filas para el mismo intento lógico". UNIQUE(provider,
// provider_order_id) no cambia — sigue siendo la garantía de "un mismo pago
// remoto de Mercado Pago nunca tiene dos filas locales".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->dropUnique(['recharge_request_id']);
            $table->unsignedInteger('attempt_number')->default(1)->after('recharge_request_id');
        });

        Schema::table('payment_orders', function (Blueprint $table) {
            $table->unique(['recharge_request_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        $this->assertAtMostOneAttemptPerRecharge();

        Schema::table('payment_orders', function (Blueprint $table) {
            $table->dropUnique(['recharge_request_id', 'attempt_number']);
            $table->dropColumn('attempt_number');
        });

        Schema::table('payment_orders', function (Blueprint $table) {
            $table->unique('recharge_request_id');
        });
    }

    /**
     * Revertir a UNIQUE(recharge_request_id) destruiría silenciosamente la
     * trazabilidad de un segundo intento real si ya existiera — abortar en
     * vez de dejar que la base de datos rechace el rollback a mitad de
     * camino con un error críptico (mismo patrón que
     * 2026_09_01_000001_add_review_status_to_payment_webhooks_table.php).
     */
    private function assertAtMostOneAttemptPerRecharge(): void
    {
        $hasMultipleAttempts = DB::table('payment_orders')
            ->select('recharge_request_id')
            ->groupBy('recharge_request_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasMultipleAttempts) {
            throw new RuntimeException(
                'Rollback abortado: existen RechargeRequest con más de un intento de pago '
                .'(payment_orders); revertir a UNIQUE(recharge_request_id) simple destruiría '
                .'esa trazabilidad.'
            );
        }
    }
};
