<?php

namespace App\Console\Commands;

use App\Services\MercadoPagoWebhookRecoveryService;
use Illuminate\Console\Command;

/**
 * Comando de recuperación durable — reutilizable, seguro de correr las
 * veces que haga falta (ver docblock de MercadoPagoWebhookRecoveryService:
 * nunca duplica créditos/reversals). NO está agendado en el scheduler —
 * decisión explícita pendiente del operador (agregar
 * `$schedule->command('mercadopago:reconcile')->everyFiveMinutes()` en
 * app/Console/Kernel.php); mientras tanto es un PRODUCTION BLOCKER
 * explícito: sin scheduler, la recuperación solo corre si alguien la
 * ejecuta a mano.
 *
 * Todos los umbrales vienen de config/payments.php ('mercadopago.recovery')
 * por defecto — las opciones de abajo solo existen para overridear un
 * barrido puntual sin tocar .env.
 *
 * Uso: php artisan mercadopago:reconcile
 *      php artisan mercadopago:reconcile --stale-minutes=10 --stuck-minutes=20
 */
class MercadoPagoReconcile extends Command
{
    protected $signature = 'mercadopago:reconcile
        {--stale-minutes= : override de mercadopago.recovery.stale_received_minutes}
        {--stuck-minutes= : override de mercadopago.recovery.stuck_order_minutes}
        {--paid-lookback-days= : override de mercadopago.recovery.paid_lookback_days}
        {--paid-lookback-min-age-minutes= : override de mercadopago.recovery.paid_lookback_min_age_minutes}
        {--max-attempts= : override de mercadopago.recovery.max_recovery_attempts}
        {--batch-size= : override de mercadopago.recovery.batch_size}
        {--uncertain-min-age-minutes= : override de mercadopago.recovery.uncertain_search_min_age_minutes}
        {--uncertain-max-attempts= : override de mercadopago.recovery.uncertain_search_max_attempts}';

    protected $description = 'Recupera webhooks/pagos de Mercado Pago atascados (received/failed/pending/paid-lookback) — nunca duplica créditos.';

    public function handle(MercadoPagoWebhookRecoveryService $recovery): int
    {
        $result = $recovery->recover(
            staleReceivedMinutes: $this->intOption('stale-minutes'),
            stuckOrderMinutes: $this->intOption('stuck-minutes'),
            paidLookbackDays: $this->intOption('paid-lookback-days'),
            paidLookbackMinAgeMinutes: $this->intOption('paid-lookback-min-age-minutes'),
            maxRecoveryAttempts: $this->intOption('max-attempts'),
            batchSize: $this->intOption('batch-size'),
            uncertainMinAgeMinutes: $this->intOption('uncertain-min-age-minutes'),
            uncertainMaxAttempts: $this->intOption('uncertain-max-attempts'),
        );

        // Reporte operacional — solo contadores, nunca payloads/secrets.
        $this->table(
            ['métrica', 'valor'],
            [
                ['payment_webhooks "received" reencolados', $result['stale_received']],
                ['payment_webhooks "failed" reintentados', $result['failed_requeued']],
                ['payment_webhooks con retry budget agotado → "review"', $result['failed_exhausted_to_review']],
                ['payment_orders pendientes reconciliadas directamente', $result['stuck_orders_reconciled']],
                ['payment_orders pendientes con error (reintento en próximo barrido)', $result['stuck_orders_errored']],
                ['payment_orders "paid" revisadas por lookback (posible refund perdido)', $result['paid_lookback_reconciled']],
                ['payment_orders "paid" del lookback con error', $result['paid_lookback_errored']],
                ['payment_orders inciertas resueltas (pago encontrado)', $result['uncertain_resolved']],
                ['payment_orders inciertas sin resolver todavía (dentro del budget)', $result['uncertain_still_uncertain']],
                ['payment_orders inciertas con budget agotado → "review"', $result['uncertain_exhausted']],
                ['payment_orders inciertas con búsqueda ambigua/inconsistente → "review"', $result['uncertain_ambiguous']],
                ['payment_orders inciertas con búsqueda fallida (reintento en próximo barrido)', $result['uncertain_search_failed']],
            ]
        );

        return self::SUCCESS;
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return $value === null ? null : (int) $value;
    }
}
