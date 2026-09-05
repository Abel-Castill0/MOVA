<?php

namespace App\Services;

use App\Exceptions\ReversalWouldGoNegative;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Notifications\RechargeApprovedNotification;
use App\Notifications\RechargeReversedNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Único punto de abono/reversión de créditos por recarga. Antes esta
 * lógica vivía inline en Admin\RechargeController::approve() — se extrajo
 * aquí para que la aprobación MANUAL de un admin y la futura acreditación
 * AUTOMÁTICA por webhook de pago (PaymentWebhookService) compartan
 * exactamente el mismo lock + idempotencia + transacción, en vez de que
 * cada camino reimplemente su propia versión y termine divergiendo (el
 * mismo problema estructural que ya motivó C-1, ver docs/HANDOFF_FINAL.md
 * §17).
 */
class RechargeApprovalService
{
    /**
     * Aprueba y abona una recarga pendiente. $reviewerId es el admin humano
     * que aprobó, o null cuando la acreditación viene de un webhook
     * automático ya verificado (sin actor humano).
     */
    public function credit(RechargeRequest $recharge, ?int $reviewerId): array
    {
        $result = $this->creditWithinTransaction($recharge, $reviewerId);

        // H-11 — EL AVISO VIVE AQUÍ, NO EN CADA LLAMANTE.
        //
        // Antes `RechargeApprovedNotification` solo se enviaba desde
        // Admin\RechargeController::approve(). Una recarga acreditada por
        // Mercado Pago (webhook, polling o barrido de reconciliación) abonaba
        // los créditos y NO avisaba a nadie: el profesor solo se enteraba si
        // dejaba abierta la pantalla de checkout
        // (docs/MOVA_SYSTEM_MAP.md §19.3, H-11).
        //
        // POR QUÉ ES EXACTAMENTE-UNA-VEZ: `changed` es true únicamente en la
        // ejecución que de verdad movió la recarga a 'approved', dentro de la
        // transacción y bajo `lockForUpdate`. Un webhook duplicado, un polling
        // simultáneo, el barrido de los 5 minutos o un reintento del job
        // encuentran la recarga ya aprobada y salen por el early-return con
        // `changed => false`. La misma garantía que ya protege al ledger
        // protege ahora al aviso, sin ningún estado extra que mantener.
        //
        // Fuera de la transacción a propósito: una cola con after_commit=false
        // podría procesar el job antes del commit y leer una recarga que
        // todavía no está aprobada.
        if ($result['changed']) {
            $result['recharge']->teacherProfile?->user?->notify(
                new RechargeApprovedNotification($result['recharge'])
            );
        }

        return $result;
    }

    private function creditWithinTransaction(RechargeRequest $recharge, ?int $reviewerId): array
    {
        try {
            return DB::transaction(function () use ($recharge, $reviewerId) {
                $recharge = RechargeRequest::whereKey($recharge->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($recharge->status === 'approved') {
                    return ['recharge' => $recharge->load('teacherProfile.user'), 'changed' => false];
                }

                // P0 (MOVA Yape Checkout Pre-Card Hardening) — el choke point
                // real, no solo la Policy: una recarga payment_method=
                // mercadopago SOLO puede pasar a 'approved' cuando $reviewerId
                // es null, es decir, cuando quien llama es
                // MercadoPagoPaymentReconciliationService::applyPaid() tras
                // confirmar el pago server-to-server con Mercado Pago — nunca
                // un admin humano ($reviewerId no-null). La idempotencia del
                // idempotency_key por sí sola no protegía esto: sin este
                // guard, un admin podía abonar créditos ANTES de que el
                // proveedor confirmara el pago (o después de que lo
                // rechazara), sin que ninguna violación de idempotencia lo
                // detectara.
                if ($reviewerId !== null && $recharge->payment_method === 'mercadopago') {
                    abort(422, 'Esta recarga es gestionada automáticamente por Mercado Pago y no puede aprobarse manualmente. Espera la confirmación del proveedor.');
                }

                // PROVIDER CREDIT CHOKE-POINT INVARIANT (MOVA Yape Final
                // Pre-Card Gate) — el guard de arriba bloquea al admin
                // humano, pero por sí solo NO prueba que $reviewerId=null
                // venga de verdad de Mercado Pago confirmando el pago: es
                // solo una convención que los dos productores actuales de
                // este llamado respetan (MercadoPagoPaymentReconciliationService
                // ::applyPaid() y PaymentWebhookService::handle()), pero un
                // caller futuro (otra herramienta, un bug) podría invocar
                // credit($recharge, null) sobre una recarga mercadopago sin
                // verdad de proveedor real. Blindaje server-side real: exigir
                // que exista un PaymentOrder ya 'paid' vinculado a esta
                // recarga. Ambos productores actuales YA marcan el
                // PaymentOrder 'paid' (misma transacción) antes de llamar
                // aquí, así que este chequeo no les cambia el
                // comportamiento — solo le cierra la puerta a cualquier otro
                // caller que no lo haya hecho.
                if ($reviewerId === null && $recharge->payment_method === 'mercadopago') {
                    abort_unless(
                        $recharge->paymentOrders()->where('status', 'paid')->exists(),
                        422,
                        'No se puede acreditar automáticamente esta recarga de Mercado Pago sin un pago confirmado por el proveedor.'
                    );
                }

                abort_if(
                    in_array($recharge->status, ['rejected', 'reversed'], true),
                    422,
                    'Esta recarga no puede aprobarse en su estado actual: '.$recharge->status
                );

                $teacherProfile = TeacherProfile::whereKey($recharge->teacher_profile_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $teacherProfile->creditTransactions()->create([
                    'idempotency_key' => "recharge:{$recharge->id}:deposit",
                    'recharge_request_id' => $recharge->id,
                    'type' => 'deposit',
                    'amount' => $recharge->credits,
                    'description' => 'Recarga de paquete: '.$recharge->package_name,
                ]);

                $teacherProfile->update([
                    'credits_available' => $teacherProfile->credits_available + $recharge->credits,
                ]);

                $recharge->update([
                    'status' => 'approved',
                    'reviewed_at' => now(),
                    'reviewed_by' => $reviewerId,
                    'approved_at' => now(),
                    'rejected_at' => null,
                    'rejection_reason' => null,
                ]);

                return ['recharge' => $recharge->load('teacherProfile.user'), 'changed' => true];
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'La recarga presenta una inconsistencia de idempotencia y requiere revisión manual.');
        }
    }

    /**
     * Revierte una recarga YA aprobada (refund/chargeback reportado por el
     * proveedor de pago, o corrección administrativa). Nunca borra ni edita
     * el depósito original — crea un asiento 'reversal' inverso (amount
     * negativo) para que el ledger siga siendo append-only y auditable.
     *
     * SALDO NEGATIVO — DOS CAMINOS CON REGLAS DISTINTAS (§7).
     *
     * `AGENTS.md` («Dinero y créditos») dice literalmente **"No permitas saldos
     * negativos"**. El docblock anterior de este método afirmaba justo lo
     * contrario ("es una decisión deliberada... política pendiente"). Era una
     * contradicción real, no una ambigüedad, y se resuelve distinguiendo QUIÉN
     * revierte:
     *
     * A) REVERSIÓN MANUAL DE UN ADMIN ($actorId !== null) → FAIL CLOSED.
     *    No hay dinero moviéndose fuera de MOVA: es una corrección
     *    administrativa. Si el profesor ya gastó esos créditos, descontarlos
     *    fabricaría una deuda silenciosa que nadie decidió. Se rechaza ANTES de
     *    escribir nada —sin asiento, sin cambio de estado, sin saldo tocado— y
     *    el caso escala a decisión humana. Es exactamente lo que pide AGENTS.md.
     *
     * B) REVERSIÓN CONFIRMADA POR EL PROVEEDOR ($actorId === null) → SE APLICA.
     *    Aquí el dinero YA volvió al pagador: Mercado Pago confirmó un reembolso
     *    total o un contracargo. Negarse a registrarlo dejaría a MOVA con
     *    créditos vivos que ningún pago respalda, que es peor que un saldo
     *    negativo: el ledger dejaría de describir la realidad. El saldo negativo
     *    resultante es el registro HONESTO de una deuda real y queda visible
     *    para resolución administrativa.
     *
     * Esta asimetría es deliberada y está pendiente de decisión de producto solo
     * en el lado B: qué hacer con esa deuda (recuperación, restricción de
     * cuenta) sigue sin definirse. Ver docs/MOVA_SYSTEM_MAP.md §16.6.
     */
    public function reverse(RechargeRequest $recharge, ?int $actorId, string $reason): array
    {
        $result = $this->reverseWithinTransaction($recharge, $actorId, $reason);

        // §5 — Una reversión legítima RESTA créditos ya abonados. Antes eso
        // ocurría en silencio: el saldo del profesor bajaba y la única
        // explicación vivía en la etiqueta del historial de créditos, que solo
        // ve quien entra a mirar (docs/MOVA_SYSTEM_MAP.md §19.3).
        //
        // Misma garantía de exactamente-una-vez que en credit(): `changed` solo
        // es true en la ejecución que realmente movió la recarga a 'reversed'.
        if ($result['changed']) {
            $result['recharge']->teacherProfile?->user?->notify(
                new RechargeReversedNotification($result['recharge'])
            );
        }

        return $result;
    }

    private function reverseWithinTransaction(RechargeRequest $recharge, ?int $actorId, string $reason): array
    {
        try {
            return DB::transaction(function () use ($recharge, $actorId, $reason) {
                $recharge = RechargeRequest::whereKey($recharge->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($recharge->status === 'reversed') {
                    return ['recharge' => $recharge->load('teacherProfile.user'), 'changed' => false];
                }

                abort_if(
                    $recharge->status !== 'approved',
                    422,
                    'Solo una recarga aprobada puede revertirse (estado actual: '.$recharge->status.').'
                );

                $teacherProfile = TeacherProfile::whereKey($recharge->teacher_profile_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // §7 — CAMINO A (admin humano): fail closed antes de escribir
                // nada. Ver el docblock de reverse() para la asimetría A/B.
                //
                // La comprobación va DENTRO de la transacción y DESPUÉS del
                // lockForUpdate del perfil: leer el saldo fuera del lock sería
                // una carrera — otra operación podría consumir créditos entre la
                // lectura y la escritura, y la reversión acabaría en negativo
                // justo en el camino donde está prohibido.
                if ($actorId !== null && $teacherProfile->credits_available < $recharge->credits) {
                    throw new ReversalWouldGoNegative(
                        (int) $teacherProfile->credits_available,
                        (int) $recharge->credits,
                    );
                }

                $teacherProfile->creditTransactions()->create([
                    'idempotency_key' => "recharge:{$recharge->id}:reversal",
                    'recharge_request_id' => $recharge->id,
                    'type' => 'reversal',
                    'amount' => -$recharge->credits,
                    'description' => 'Reversión de recarga: '.$reason,
                ]);

                $teacherProfile->update([
                    'credits_available' => $teacherProfile->credits_available - $recharge->credits,
                ]);

                $recharge->update([
                    'status' => 'reversed',
                    'reversed_at' => now(),
                    'reversed_by' => $actorId,
                    'reversal_reason' => $reason,
                ]);

                return ['recharge' => $recharge->load('teacherProfile.user'), 'changed' => true];
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'La reversión ya fue procesada (idempotencia).');
        }
    }
}
