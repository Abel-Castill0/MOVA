<?php

namespace App\Services;

use App\Models\PaymentOrder;
use App\Models\PaymentWebhook;
use App\Payment\PaymentWebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Punto único de procesamiento para un evento YA verificado (el llamador
 * debe haber pasado el payload crudo por
 * PaymentProviderContract::verifyWebhook() primero — este servicio nunca
 * verifica firmas ni conoce el formato de un proveedor específico).
 *
 * Flujo (ver docs/payments-architecture.md):
 *   1. Persistir el evento en payment_webhooks — UNIQUE(provider, event_id)
 *      hace que un reintento/duplicado del proveedor nunca se procese dos
 *      veces, con garantía de base de datos, no solo de código.
 *   2. Si es nuevo: bajo lock, resolver el PaymentOrder y transicionar su
 *      estado.
 *   3. Si el evento confirma el pago: delegar el abono de créditos a
 *      RechargeApprovalService::credit() — este servicio JAMÁS toca
 *      credit_transactions/teacher_profiles directamente.
 *
 * No expone ninguna ruta HTTP todavía — se llama directamente desde tests
 * y, cuando exista CulqiPaymentProvider real, desde el controlador de
 * webhook que se agregue en esa fase.
 */
class PaymentWebhookService
{
    public function __construct(private readonly RechargeApprovalService $approvals)
    {
    }

    public function handle(string $provider, PaymentWebhookEvent $event): PaymentWebhook
    {
        try {
            $webhook = PaymentWebhook::create([
                'provider' => $provider,
                'event_id' => $event->eventId,
                'event_type' => $event->eventType,
                'payload' => $event->rawPayload,
                'payload_hash' => hash('sha256', json_encode($event->rawPayload)),
                'received_at' => now(),
                'status' => 'received',
            ]);
        } catch (UniqueConstraintViolationException) {
            // Evento ya visto antes (retry/duplicado del proveedor) — no se
            // reprocesa; se devuelve el registro original tal cual quedó.
            return PaymentWebhook::where('provider', $provider)
                ->where('event_id', $event->eventId)
                ->firstOrFail();
        }

        try {
            DB::transaction(function () use ($event, $provider) {
                $order = PaymentOrder::where('provider', $provider)
                    ->where('provider_order_id', $event->providerOrderId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($order->status === 'paid') {
                    return; // ya procesada por un evento anterior — idempotente
                }

                abort_if(
                    in_array($order->status, ['failed', 'expired', 'cancelled'], true),
                    422,
                    'La orden de pago ya está en un estado terminal: '.$order->status
                );

                if ($event->status === 'paid') {
                    $order->update(['status' => 'paid', 'paid_at' => now()]);
                    // reviewerId null: acreditado por el sistema, no por un admin humano.
                    $this->approvals->credit($order->rechargeRequest, null);
                } elseif ($event->status === 'failed') {
                    $order->update(['status' => 'failed']);
                }
            });

            $webhook->update(['status' => 'processed', 'processed_at' => now()]);
        } catch (Throwable $e) {
            $webhook->update(['status' => 'failed', 'error' => substr($e->getMessage(), 0, 500)]);
            throw $e;
        }

        return $webhook->fresh();
    }
}
