<?php

namespace App\Console\Commands;

use App\Models\OperationalAlert;
use App\Models\PaymentOrder;
use App\Payment\CulqiPaymentProvider;
use App\Services\CulqiPaymentReconciliationService;
use App\Services\OperationalAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Barrido de recuperación de Culqi: reconcilia contra la API los intentos
 * pendientes con id de cargo y busca los inciertos (timeout/5xx al crear).
 * Es la red de seguridad cuando un webhook (que Culqi no firma) no llega.
 * Solo lecturas a Culqi; las escrituras locales pasan por
 * CulqiPaymentReconciliationService.
 *
 * El barrido automático NO toca intentos ya en revisión humana ni 'prepared'
 * (nunca se envió nada): así no se repiten alertas ni se mata de hambre a los
 * intentos nuevos. Para volver a comprobar uno concreto, incluso en revisión:
 * `php artisan culqi:reconcile --order=<id>`.
 */
class CulqiReconcile extends Command
{
    protected $signature = 'culqi:reconcile
        {--minutes= : antigüedad mínima del intento (por defecto CULQI_RECOVERY_STUCK_ORDER_MINUTES)}
        {--order= : reconcilia solo este PaymentOrder (id), incluso si está en revisión}';

    protected $description = 'Culqi: reconcilia pagos pendientes/inciertos contra la API (idempotente).';

    public function handle(CulqiPaymentReconciliationService $reconciler): int
    {
        $query = PaymentOrder::where('provider', CulqiPaymentProvider::PROVIDER);

        if ($this->option('order') !== null) {
            $query->whereKey((int) $this->option('order'));
        } else {
            $query->where('status', 'pending');
            $minutes = (int) ($this->option('minutes') ?? config('payments.culqi.recovery.stuck_order_minutes', 15));
            $query->whereNull('review_reason')
                ->where(function ($q) use ($minutes) {
                    $q->where(function ($pending) use ($minutes) {
                        $pending->whereIn('submission_status', ['submitting', 'uncertain', 'submitted'])
                            ->where('created_at', '<=', now()->subMinutes($minutes));
                    })->orWhere(function ($expired3ds) {
                        $expired3ds->where('submission_status', 'awaiting_3ds')
                            ->whereNotNull('three_ds_expires_at')
                            ->where('three_ds_expires_at', '<=', now());
                    });
                });
        }

        $batch = (int) config('payments.culqi.recovery.batch_size', 100);
        $orders = $query->orderBy('id')->limit($batch)->get();

        // Red de seguridad para devoluciones/disputas cuyo webhook no llegó: pagos ya 'paid' dentro de la
        // ventana de recuperación se re-verifican como mucho una vez por hora (los menos recientes primero).
        if ($this->option('order') === null) {
            $lookbackDays = (int) config('payments.culqi.recovery.paid_lookback_days', 14);
            $paid = PaymentOrder::where('provider', CulqiPaymentProvider::PROVIDER)
                ->where('status', 'paid')
                ->whereNotNull('provider_order_id')
                ->where('paid_at', '>=', now()->subDays($lookbackDays))
                ->where(fn ($q) => $q->whereNull('last_verified_at')->orWhere('last_verified_at', '<=', now()->subHour()))
                ->orderByRaw('last_verified_at IS NOT NULL, last_verified_at')
                ->limit($batch)
                ->get();
            $orders = $orders->concat($paid);
        }

        $counts = [];
        $verifiedRemotely = false;
        foreach ($orders as $order) {
            try {
                if ($order->submission_status === 'awaiting_3ds') {
                    if ($order->three_ds_expires_at === null || $order->three_ds_expires_at->isFuture()) {
                        $outcome = 'awaiting_3ds';
                    } else {
                        $outcome = app(CulqiPaymentProvider::class)->expireThreeDsOrder($order)
                            ? 'expired_3ds' : 'already_resolved';
                    }
                } elseif ($order->provider_order_id === null && $order->status !== 'pending') {
                    // --order también admite pagados/revisados, pero una orden
                    // terminal sin id remoto no debe gastar búsquedas de un
                    // intento que MOVA ya cerró sin cargo identificable.
                    $outcome = 'no_remote_id';
                } else {
                    if ($order->provider_order_id === null) {
                        $outcome = $reconciler->reconcileUncertainSubmission($order);
                        // 'uncertain'/'review' solo salen tras un listado
                        // remoto correcto; un HTTP fallido devuelve ahora
                        // 'provider_unavailable' sin agotar el presupuesto.
                        $verifiedRemotely = $verifiedRemotely || in_array($outcome, ['paid', 'failed', 'reversed', 'uncertain', 'review'], true);
                    } else {
                        $outcome = $reconciler->reconcile($order);
                        // reconcile() solo retorna después de un GET correcto
                        // y applyTruth(); ante error HTTP lanza excepción.
                        $verifiedRemotely = true;
                    }
                }
            } catch (Throwable $e) {
                $outcome = 'error';
                // El scheduler ejecuta en background y descarta stdout: un warn()
                // aislado ocultaba la caída de la API o de la conciliación.
                // No incluir el mensaje: podría arrastrar URL o datos del proveedor.
                Log::error('[Culqi/Reconcile] Fallo al conciliar un intento.', [
                    'payment_order_id' => $order->id,
                    'exception_class' => class_basename($e),
                ]);
                $this->warn("PaymentOrder#{$order->id}: ".class_basename($e));
            }
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
        }

        $errors = ($counts['error'] ?? 0) + ($counts['provider_unavailable'] ?? 0);
        if ($this->option('order') === null) {
            // Una sola incidencia por barrido, aunque Culqi falle para 100
            // cargos: se evita notificar 100 veces al administrador.
            $alerts = app(OperationalAlertService::class);
            if ($errors > 0) {
                $alerts->raise(
                    key: 'culqi:reconcile:failure',
                    type: OperationalAlert::TYPE_RECONCILIATION_FAILURE,
                    title: 'Culqi: fallos en la conciliación automática',
                    message: 'No se pudo verificar al menos un pago con Culqi. Los créditos permanecen sin cambios hasta recuperar la verdad del proveedor.',
                    context: ['Órdenes fallidas' => $errors, 'Órdenes revisadas' => $orders->count()],
                    severity: OperationalAlert::SEVERITY_WARNING,
                );
            } elseif ($verifiedRemotely) {
                // No cerrar una alerta de caída si la pasada solo expiró 3DS
                // localmente o no encontró órdenes: no hay prueba de que
                // Culqi haya vuelto a responder correctamente.
                $alerts->resolve('culqi:reconcile:failure');
            }
        }

        $this->info('Revisados: '.$orders->count().($counts ? ' · '.collect($counts)->map(fn ($n, $k) => "{$k}={$n}")->implode(' ') : ''));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
