<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Representa UN intento de pago (no necesariamente "una order" en el
 * sentido de Mercado Pago Orders API — ese nombre se conserva a propósito
 * para evitar un rename grande de tabla/modelo sin beneficio real; ver
 * decisión de la sesión de pivot a Payments API). `provider_order_id`
 * guarda la referencia del proveedor al recurso remoto — hoy siempre el
 * `id` numérico de un pago de Mercado Pago Payments API (`GET
 * /v1/payments/{id}`), nunca un id de Orders API.
 *
 * Una RechargeRequest puede tener VARIOS PaymentOrder (uno por intento —
 * ver `attempt_number` y la migración que lo introdujo): un intento con
 * tarjeta rechazada no bloquea un segundo intento legítimo, p. ej. con
 * Yape, sobre la MISMA RechargeRequest. El ledger sigue siendo
 * exactamente-once por RechargeRequest sin importar cuántos intentos existan
 * (ver RechargeApprovalService::credit()).
 */
class PaymentOrder extends Model
{
    protected $fillable = [
        'recharge_request_id',
        'attempt_number',
        'idempotency_key',
        'provider',
        'provider_order_id',
        'status',
        'submission_status',
        'recovery_attempts',
        'provider_status',
        'provider_status_detail',
        'review_reason',
        'review_detected_at',
        'review_resolved_at',
        'amount_minor',
        'currency',
        'expires_at',
        'paid_at',
        'last_verified_at',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'recovery_attempts' => 'integer',
        'amount_minor' => 'integer',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'last_verified_at' => 'datetime',
        'review_detected_at' => 'datetime',
        'review_resolved_at' => 'datetime',
    ];

    public function rechargeRequest()
    {
        return $this->belongsTo(RechargeRequest::class);
    }

    /**
     * Referencia inmutable y ESPECÍFICA DE ESTE INTENTO — la misma cadena
     * que MercadoPagoPaymentProvider::createPaymentAttempt() envía como
     * `external_reference` al crear el pago, y la que
     * MercadoPagoPaymentReconciliationService usa tanto para validar la
     * verdad server-to-server (truthMatchesExpectedContext()) como para
     * buscar un pago incierto (`GET /v1/payments/search?external_reference=`,
     * ver reconcileUncertainSubmission()). Se computa siempre a partir de
     * columnas ya persistidas e inmutables (recharge_request_id,
     * attempt_number) en vez de guardarse aparte — un solo lugar de verdad,
     * imposible que quede desincronizado.
     */
    public function externalReference(): string
    {
        return "recharge:{$this->recharge_request_id}:attempt:{$this->attempt_number}";
    }
}
