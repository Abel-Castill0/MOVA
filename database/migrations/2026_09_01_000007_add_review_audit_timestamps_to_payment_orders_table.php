<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// REVIEW AUDIT: review_reason (migración 2026_09_01_000005) solo describe
// la anomalía ACTIVA — se limpia a null en cuanto se resuelve, así que un
// operador no podía distinguir "este intento NUNCA tuvo una anomalía" de
// "tuvo una anomalía y ya se resolvió" (ambos casos: review_reason=null).
// Dos timestamps, sin subsistema nuevo, sobre la misma PaymentOrder:
//
//   - review_detected_at: cuándo empezó el episodio de revisión ACTUAL.
//     Se fija la primera vez que review_reason pasa de null a un motivo, y
//     NO se vuelve a tocar mientras la anomalía sigue activa (repetidas
//     detecciones durante el mismo episodio no reinician el reloj).
//   - review_resolved_at: cuándo se resolvió el episodio de revisión más
//     reciente. Se fija cuando review_reason vuelve a null DESPUÉS de haber
//     tenido un valor; permanece null mientras la anomalía sigue activa.
//
// Tres estados distinguibles para un operador (ver
// MercadoPagoPaymentReconciliationService::markReview()/recordResolvedTruth()):
//   nunca hubo anomalía      → detected_at=null,      resolved_at=null
//   anomalía activa          → detected_at=<fecha>,    resolved_at=null
//   anomalía ya resuelta     → detected_at=<fecha>,    resolved_at=<fecha>
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->timestamp('review_detected_at')->nullable()->after('review_reason');
            $table->timestamp('review_resolved_at')->nullable()->after('review_detected_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->dropColumn(['review_detected_at', 'review_resolved_at']);
        });
    }
};
