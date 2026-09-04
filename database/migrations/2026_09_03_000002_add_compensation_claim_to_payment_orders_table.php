<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// COMPENSATION CLAIM (ronda de auditoría de seguridad financiera final,
// LOST 3DS CHALLENGE): columna ANGOSTA y de propósito único — un mutex
// local, corto y auto-expirable para que
// MercadoPagoPaymentReconciliationService::compensateLostChallenge()
// garantice que, para UN PaymentOrder dado, como mucho UN worker llega a
// invocar MercadoPagoPaymentProvider::cancelPayment() a la vez.
//
// POR QUÉ UNA COLUMNA NUEVA (no una reutilizada) — investigado y
// descartado explícitamente, con evidencia trazada, cada campo existente
// candidato:
//   - `provider_status`: descartado de raíz — su migración (2026_09_01_
//     000005) documenta EXPLÍCITAMENTE el contrato "último status/
//     status_detail CRUDO que el proveedor reportó". Un valor sintético
//     de MOVA ahí ("compensating_cancel", usado en una ronda anterior de
//     esta misma feature) viola ese contrato — un operador leyendo esta
//     columna esperando la verdad del proveedor vería un valor que
//     Mercado Pago nunca reportó.
//   - `review_reason` (y sus timestamps de auditoría): descartado tras
//     trazar la ejecución real, no por suposición — el ALGORITMO exige
//     un GET canónico (`reconcile()`) tanto ANTES como DESPUÉS de la
//     cancelación (secciones 3/6 del encargo), y `reconcile()` SIEMPRE
//     limpia `review_reason` a null en cualquier desenlace no-review vía
//     `recordResolvedTruth()` — es exactamente lo que la hace correcta
//     como "estado de revisión actual, nunca un historial". Eso significa
//     que un claim guardado ahí se borraría SOLO por el GET previo de
//     OTRO worker concurrente, sin que el primer worker se enterara —
//     dos workers podrían terminar llamando a cancelPayment() igual.
//   - `submission_status`: es la ÚNICA columna de diagnóstico que
//     `reconcile()` nunca toca en ninguna rama — pero tiene su PROPIO
//     contrato angosto y ya confiado por
//     `MercadoPagoPaymentProvider::resolveAttemptRow()` ("cuatro valores
//     activos, no una state machine grande", ver migración 2026_09_01_
//     000006). Meterle un quinto valor sintético habría sido exactamente
//     el mismo tipo de violación de contrato que `provider_status`, solo
//     que sobre otra columna.
//
// `compensation_claimed_at` (nullable): NULL = sin claim activo. Un
// timestamp reciente = un worker reclamó la compensación y está en
// medio de la ronda (2 GET + 1 PUT). Un timestamp más viejo que
// MercadoPagoPaymentReconciliationService::COMPENSATION_CLAIM_STALE_MINUTES
// (5 minutos — tiempo de sobra para una ronda completa con los timeouts
// HTTP ya configurados) se trata como abandonado (el worker murió antes
// de completar la ronda) — un worker posterior puede reclamarlo de
// nuevo, nunca antes. Se limpia explícitamente a null en cuanto la
// compensación resuelve el intento a un desenlace TERMINAL
// (paid/failed/reversed/review) — nunca queda un claim huérfano una vez
// que la compensación realmente terminó. `reconcile()` (el choke point
// compartido por webhook/recovery/polling) NUNCA lee ni escribe esta
// columna — su ciclo de vida completo vive exclusivamente dentro de
// compensateLostChallenge(), sin mezclar lógica de claim en código
// compartido y ya probado.
//
// NO es verdad financiera, NO es un estado de negocio nuevo del
// PaymentOrder (status/submission_status siguen siendo los únicos que
// gobiernan crédito/reintento) — puramente un mutex de orquestación
// local, mismo espíritu que `submission_status='submitting'` (marcador
// PRE-I/O persistido antes de una llamada de red para que un crash a
// mitad de camino sea recuperable), pero en su propia columna para no
// pisar un contrato ajeno.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->timestamp('compensation_claimed_at')->nullable()->after('recovery_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->dropColumn('compensation_claimed_at');
        });
    }
};
