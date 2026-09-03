<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// REVIEW DURABILITY: antes, una anomalía financiera detectada por
// MercadoPagoPaymentReconciliationService (monto/moneda/referencia/cuenta
// equivocados, refund parcial, verdad malformada, retry budget agotado)
// solo dejaba rastro en payment_webhooks.status='review'/error — y NADA
// cuando el llamador era MercadoPagoWebhookRecoveryService reconciliando
// una PaymentOrder huérfana (sin payment_webhooks, $webhook=null): en ese
// caso la única evidencia era una línea de log, que no es una fuente de
// verdad operable ("un simple log no basta"). Un operador no podía navegar
// RechargeRequest → attempt → provider payment id → motivo de revisión sin
// grepear logs.
//
// Estas columnas SON provider-agnostic (no específicas de Mercado Pago) y
// reutilizan la fila PaymentOrder ya existente — sin subsistema de
// casos/manual-review nuevo:
//   - provider_status/provider_status_detail: último status/status_detail
//     crudo que el proveedor reportó, para diagnóstico sin tener que
//     volver a llamar a la API.
//   - review_reason: motivo de la última revisión pendiente sobre ESTE
//     intento — se limpia (null) en cuanto una reconciliación posterior
//     resuelve el intento a un estado no ambiguo (paid/pending/failed/
//     reversed), y se vuelve a fijar si una reconciliación futura vuelve a
//     encontrar una anomalía. No es un log histórico, es el estado ACTUAL.
//   - last_verified_at: cuándo se confirmó por última vez la verdad
//     server-to-server de este intento — permite distinguir "nunca se
//     reconcilió" de "se reconcilió hace mucho".
//
// Índice en paid_at: MercadoPagoWebhookRecoveryService::reconcilePaidLookback()
// consulta por rango sobre esta columna en cada barrido — sin índice sería
// un table scan a medida que crece el historial de pagos.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->string('provider_status', 64)->nullable()->after('status');
            $table->string('provider_status_detail', 64)->nullable()->after('provider_status');
            $table->string('review_reason', 500)->nullable()->after('provider_status_detail');
            $table->timestamp('last_verified_at')->nullable()->after('paid_at');
            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->dropIndex(['paid_at']);
            $table->dropColumn(['provider_status', 'provider_status_detail', 'review_reason', 'last_verified_at']);
        });
    }
};
