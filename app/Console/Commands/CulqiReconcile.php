<?php

namespace App\Console\Commands;

use App\Models\PaymentOrder;
use App\Models\RechargeRequest;
use App\Payment\CulqiPaymentProvider;
use App\Services\CulqiPaymentReconciliationService;
use Illuminate\Console\Command;
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
        foreach ($orders as $order) {
            try {
                if ($order->submission_status === 'awaiting_3ds') {
                    if ($order->three_ds_expires_at === null || $order->three_ds_expires_at->isFuture()) {
                        $outcome = 'awaiting_3ds';
                    } else {
                        app(CulqiPaymentProvider::class)->abandonThreeDs(
                            RechargeRequest::findOrFail($order->recharge_request_id), '3ds_expired'
                        );
                        $outcome = 'expired_3ds';
                    }
                } else {
                    $outcome = $order->provider_order_id === null
                        ? $reconciler->reconcileUncertainSubmission($order)
                        : $reconciler->reconcile($order);
                }
            } catch (Throwable $e) {
                $outcome = 'error';
                $this->warn("PaymentOrder#{$order->id}: ".class_basename($e));
            }
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
        }

        $this->info('Revisados: '.$orders->count().($counts ? ' · '.collect($counts)->map(fn ($n, $k) => "{$k}={$n}")->implode(' ') : ''));

        return self::SUCCESS;
    }
}
