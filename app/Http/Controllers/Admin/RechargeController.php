<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ReversalWouldGoNegative;
use App\Http\Controllers\Controller;
use App\Models\OperationalAlert;
use App\Models\RechargeRequest;
use App\Notifications\RechargeRejectedNotification;
use App\Services\OperationalAlertService;
use App\Services\RechargeApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class RechargeController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Recharges/Index', [
            // latestPaymentOrder: la vista necesita distinguir una recarga
            // provider-managed (payment_method=mercadopago, sin acciones
            // manuales — ver RechargeRequestPolicy::approve()/reject()) de
            // una manual, y mostrar en qué quedó el intento automático en
            // vez de dejar un "pendiente" que en realidad ya lo resolvió
            // Mercado Pago.
            'recharges' => RechargeRequest::with(['teacherProfile.user', 'reviewer', 'latestPaymentOrder'])
                ->latest()
                ->paginate(30)
                ->withQueryString(),
        ]);
    }

    public function approve(RechargeRequest $recharge)
    {
        $this->authorize('approve', $recharge);

        // Delegado a RechargeApprovalService: el mismo método que usará la
        // acreditación automática por webhook de pago (PaymentWebhookService)
        // cuando exista un proveedor real — un solo camino para tocar el
        // ledger, no dos que puedan divergir.
        // H-11 — El aviso al profesor lo emite RechargeApprovalService::credit(),
        // no este controller. Antes vivía aquí, y por eso una recarga acreditada
        // automáticamente por Mercado Pago no avisaba a nadie. Duplicarlo aquí
        // enviaría el correo dos veces por la aprobación manual.
        $result = app(RechargeApprovalService::class)->credit($recharge, auth()->id());

        return back()->with(
            'success',
            $result['changed']
                ? 'Recarga aprobada y créditos abonados correctamente.'
                : 'La recarga ya estaba aprobada; no se abonaron créditos adicionales.'
        );
    }

    /**
     * H-02 — Revertir una recarga ya aprobada.
     *
     * El servicio (RechargeApprovalService::reverse()) existía, estaba probado y
     * era INALCANZABLE: solo lo llamaba la conciliación automática de Mercado
     * Pago. Un Yape falso aprobado a mano no tenía forma de deshacerse desde el
     * producto.
     *
     * Toda la mecánica financiera —transacción, lock, asiento `reversal`
     * negativo, idempotencia por `recharge:{id}:reversal`— ya vive en el
     * servicio. Este controller solo aporta el límite HTTP: autorización,
     * motivo obligatorio, auditoría y una respuesta honesta.
     *
     * SOBRE EL SALDO INSUFICIENTE (§7): la reversion MANUAL se rechaza en el
     * servicio ANTES de escribir nada si dejaria el saldo en negativo, porque
     * `AGENTS.md` lo prohibe explicitamente y aqui no hay dinero saliendo de
     * MOVA: es una correccion administrativa. El rechazo abre una incidencia
     * critica para que un humano decida (cobro, restriccion o condonacion).
     *
     * La reversion CONFIRMADA POR EL PROVEEDOR sigue otro camino y si puede
     * dejar saldo negativo: alli el dinero ya volvio al pagador y negarse
     * dejaria a MOVA con creditos que ningun pago respalda. Ver el docblock de
     * RechargeApprovalService::reverse().
     */
    public function reverse(Request $request, RechargeRequest $recharge)
    {
        $this->authorize('reverse', $recharge);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ], [
            'reason.required' => 'Explica por qué se revierte esta recarga: queda registrado en el ledger.',
            'reason.min' => 'El motivo debe ser lo bastante específico para que otra persona lo entienda después (mínimo 10 caracteres).',
        ]);

        try {
            $result = app(RechargeApprovalService::class)->reverse(
                $recharge,
                $request->user()->id,
                $data['reason']
            );
        } catch (ReversalWouldGoNegative $e) {
            // §7 — El servicio rechaza la reversión manual que dejaría el saldo
            // en negativo (AGENTS.md: "No permitas saldos negativos"). No se
            // movió ni un crédito, pero el caso SÍ necesita a un humano: el
            // profesor consumió créditos que la recarga revertida respaldaba.
            app(OperationalAlertService::class)->raise(
                key: "recharge:{$recharge->id}:manual_reversal_blocked",
                type: OperationalAlert::TYPE_LEDGER_ANOMALY,
                title: 'Reversión manual bloqueada por saldo insuficiente',
                message: 'Un administrador intentó revertir una recarga aprobada, pero el profesor ya había '
                    .'consumido esos créditos. MOVA NO ha tocado el saldo ni el ledger: descontarlos habría '
                    .'creado una deuda silenciosa. Requiere una decisión humana explícita (cobro, '
                    .'restricción de cuenta o condonación).',
                context: [
                    'Recarga' => $recharge->id,
                    'Profesor (perfil)' => $recharge->teacher_profile_id,
                    'Créditos de la recarga' => $recharge->credits,
                    'Saldo disponible' => $recharge->teacherProfile?->credits_available,
                    'Motivo indicado' => $data['reason'],
                    'Administrador' => $request->user()->id,
                ],
                severity: OperationalAlert::SEVERITY_CRITICAL,
            );

            Log::warning('ADMIN_RECHARGE_REVERSAL_BLOCKED', [
                'admin_id' => $request->user()->id,
                'recharge_request_id' => $recharge->id,
                'reason' => $data['reason'],
            ]);

            return back()->with('error', $e->getMessage());
        }

        if (! $result['changed']) {
            return back()->with('success', 'Esta recarga ya estaba revertida; no se descontaron créditos adicionales.');
        }

        $reversed = $result['recharge'];
        $balance = $reversed->teacherProfile?->fresh()?->credits_available ?? 0;

        Log::info('ADMIN_RECHARGE_REVERSED', [
            'admin_id' => $request->user()->id,
            'recharge_request_id' => $reversed->id,
            'teacher_profile_id' => $reversed->teacher_profile_id,
            'credits' => $reversed->credits,
            'reason' => $data['reason'],
            'resulting_available_balance' => $balance,
        ]);

        return back()->with(
            'success',
            "Recarga revertida: se descontaron {$reversed->credits} créditos. El profesor ha sido notificado."
        );
    }

    public function reject(Request $request, RechargeRequest $recharge)
    {
        $this->authorize('reject', $recharge);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $reviewerId = $request->user()->id;

        $result = DB::transaction(function () use ($recharge, $data, $reviewerId) {
            $recharge = RechargeRequest::whereKey($recharge->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($recharge->status === 'rejected') {
                return ['recharge' => $recharge->load('teacherProfile.user'), 'changed' => false];
            }

            abort_if($recharge->status === 'approved', 422, 'Una recarga aprobada no puede rechazarse.');

            $recharge->update([
                'status' => 'rejected',
                'reviewed_at' => now(),
                'reviewed_by' => $reviewerId,
                'approved_at' => null,
                'rejected_at' => now(),
                'rejection_reason' => $data['reason'],
            ]);

            return ['recharge' => $recharge->load('teacherProfile.user'), 'changed' => true];
        });

        if ($result['changed']) {
            $result['recharge']->teacherProfile?->user?->notify(
                new RechargeRejectedNotification($result['recharge'], $data['reason'])
            );
        }

        return back()->with(
            'success',
            $result['changed'] ? 'Recarga rechazada correctamente.' : 'La recarga ya estaba rechazada.'
        );
    }
}
