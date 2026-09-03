<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// UNCERTAIN SUBMISSION HARDENING: antes, "¿esta fila necesita recuperación
// por external_reference antes de poder reutilizarse?" se INFERÍA como
// `provider_order_id === null && status === 'pending'` — una inferencia
// correcta mientras solo existía un motivo posible para ese estado
// (excepción de red durante el POST). Esta ronda agrega varios motivos más
// (429/5xx/409 idempotency-conflict/2xx sin id — ver
// MercadoPagoPaymentProvider::classifyHttpFailure()) y, más importante,
// necesita que resolveAttemptRow() pueda DISTINGUIR explícitamente "este
// intento nunca se confirmó, hay que investigar antes de reutilizarlo" —
// preferir un campo claro a una inferencia (ver sección "PAYMENT ATTEMPT
// STATE" del encargo).
//
// submission_status es DELIBERADAMENTE mínimo (SUBMISSION LIFECYCLE, ronda
// del pre-commit gate) — cuatro valores activos, no una state machine
// grande, cada uno respondiendo la única pregunta que
// MercadoPagoPaymentProvider::resolveAttemptRow() necesita:
//   'prepared'   — fila creada/comprometida, NINGÚN POST intentado todavía
//                  (incluye "el proceso murió justo después del commit,
//                  antes de iniciar el envío") — reutilizable directamente,
//                  incluso con un token/medio de pago nuevo.
//   'submitting' — a punto de/enviando el POST — persistido ANTES de la
//                  llamada HTTP para que un crash justo ahí sea
//                  recuperable; tratado igual que 'uncertain'.
//   'uncertain'  — el POST corrió y no dio una respuesta definitiva
//                  (timeout/429/5xx/409 idempotency-conflict/2xx sin id —
//                  ver MercadoPagoPaymentProvider::classifyHttpFailure()).
//   'submitted'  — provider payment id conocido (2xx con id).
// null = sin tracking activo — cubre tanto una fila ya resuelta (limpiada
// tras una reconciliación) como un bloqueo OPERACIONAL (error de
// integración/autorización 401/403/404: `status` se deja 'pending' a
// propósito para que bloquee igual que un intento activo, ver
// MercadoPagoPaymentProvider::createPaymentAttempt()). 'submitting' y
// 'uncertain' son candidatos a recuperación dirigida por external_reference
// (MercadoPagoPaymentReconciliationService::reconcileUncertainSubmission()).
//
// recovery_attempts: presupuesto de reintentos de ESA búsqueda dirigida —
// distinto de payment_webhooks.recovery_attempts (que cuenta reintentos del
// job de webhook) y de PaymentOrder.attempt_number (que cuenta intentos de
// COBRO, no de investigación). Sin este tope, un intento que Mercado Pago
// nunca llegó a crear (0 resultados de búsqueda para siempre) mantendría
// a MOVA reintentando la búsqueda indefinidamente en cada barrido, sin
// nunca caer a revisión humana.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->string('submission_status', 20)->nullable()->after('status');
            $table->unsignedInteger('recovery_attempts')->default(0)->after('submission_status');
            $table->index('submission_status');
        });
    }

    public function down(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->dropIndex(['submission_status']);
            $table->dropColumn(['submission_status', 'recovery_attempts']);
        });
    }
};
