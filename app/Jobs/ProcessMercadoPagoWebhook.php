<?php

namespace App\Jobs;

use App\Models\PaymentOrder;
use App\Models\PaymentWebhook;
use App\Payment\MercadoPagoPaymentProvider;
use App\Services\MercadoPagoPaymentReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Procesamiento durable y ASÍNCRONO de una notificación de Mercado Pago ya
 * autenticada y persistida (MercadoPagoWebhookController solo verifica
 * firma + guarda payment_webhooks, y encola esto — nunca hace el trabajo
 * financiero dentro del ciclo de la request HTTP).
 *
 * Envoltorio DELGADO a propósito: toda la decisión financiera
 * (fetchPayment(), validación de monto/referencia/moneda/cuenta,
 * normalización de estado, credit()/reverse()) vive en
 * MercadoPagoPaymentReconciliationService — compartida con
 * MercadoPagoWebhookRecoveryService, que reconcilia intentos de pago
 * atascados SIN depender de que exista un payment_webhooks (notificación
 * nunca entregada).
 * Este job solo resuelve "¿qué PaymentOrder local corresponde a este
 * payment_webhooks?" y hace el bookkeeping de payment_webhooks.status.
 *
 * Se dispatcha con ->afterCommit() desde el controller para no correr
 * nunca antes de que el INSERT de payment_webhooks haya committeado (si
 * corriera antes y luego el commit fallara, el job vería una fila que
 * técnicamente no existe todavía).
 */
class ProcessMercadoPagoWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    // Backoff creciente: un fallo de fetchOrder() suele ser Mercado Pago
    // caído/lento (ver MP_API_DOWN ya observado en esta cuenta) — no tiene
    // sentido reintentar cada segundo.
    public array $backoff = [10, 30, 60, 300, 900];

    public function __construct(public readonly int $paymentWebhookId)
    {
    }

    public function handle(MercadoPagoPaymentProvider $provider, MercadoPagoPaymentReconciliationService $reconciler): void
    {
        $webhook = PaymentWebhook::find($this->paymentWebhookId);
        if (! $webhook) {
            Log::error('[MercadoPago/Job] payment_webhooks no encontrado — no se puede procesar.', [
                'payment_webhook_id' => $this->paymentWebhookId,
            ]);

            return;
        }

        // Idempotencia también aquí: un reintento del propio job (fallo
        // transitorio previo ya resuelto por otro run, o un dispatch
        // duplicado por error) no debe reprocesar un webhook que ya llegó
        // a un estado terminal.
        if (in_array($webhook->status, ['processed', 'review'], true)) {
            return;
        }

        $providerOrderId = $webhook->payload['data']['id'] ?? null;
        if (! is_string($providerOrderId) || $providerOrderId === '') {
            // Sin PaymentOrder identificable todavía (no hay data.id que
            // buscar) — markReview() acepta $order=null para este caso; la
            // constancia queda en payment_webhooks/log solamente.
            $reconciler->markReview(null, $webhook, 'payment_webhooks.payload sin data.id al momento de procesar.');

            return;
        }

        $order = PaymentOrder::where('provider', 'mercadopago')
            ->where('provider_order_id', $providerOrderId)
            ->first();

        if (! $order) {
            // No es necesariamente un ataque: puede ser una notificación
            // que llegó ANTES de que el INSERT de createPaymentAttempt()
            // committeara (carrera legítima) — reintentable, no 'review'
            // definitivo.
            throw new RuntimeException(
                "ProcessMercadoPagoWebhook: no existe PaymentOrder local para provider_order_id={$providerOrderId} (payment_webhook={$webhook->id})."
            );
        }

        $reconciler->reconcile($order, $webhook, $provider);

        $webhook->refresh();
        if ($webhook->status === 'received') {
            // Ninguna rama de la reconciliación lo dejó en 'review' — se
            // entendió y se actuó correctamente (incluso si la acción
            // correcta fue "nada", como en 'pending').
            $webhook->update(['status' => 'processed', 'processed_at' => now()]);
        }
    }

    /**
     * Falla definitivamente el job de Laravel después de agotar los
     * reintentos — se registra en failed_jobs para investigación manual, sin
     * dejar el webhook colgado en 'received' para siempre.
     * MercadoPagoWebhookRecoveryService reencola automáticamente los
     * webhooks que terminan aquí (ver ese servicio).
     */
    public function failed(Throwable $exception): void
    {
        $webhook = PaymentWebhook::find($this->paymentWebhookId);
        $webhook?->update([
            'status' => 'failed',
            'error' => substr($exception->getMessage(), 0, 500),
        ]);

        Log::critical('[MercadoPago/Job] Agotados los reintentos — requiere investigación manual.', [
            'payment_webhook_id' => $this->paymentWebhookId,
            'error' => $exception->getMessage(),
        ]);
    }
}
