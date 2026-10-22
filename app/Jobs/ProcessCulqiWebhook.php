<?php

namespace App\Jobs;

use App\Models\OperationalAlert;
use App\Models\PaymentOrder;
use App\Models\PaymentWebhook;
use App\Payment\CulqiPaymentProvider;
use App\Services\CulqiPaymentReconciliationService;
use App\Services\OperationalAlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Procesa una notificación de Culqi ya persistida en payment_webhooks.
 *
 * El cuerpo del webhook es solo una PISTA de qué cargo cambió: Culqi no firma
 * sus notificaciones. Este job nunca acredita en base a lo que dice el payload;
 * localiza el PaymentOrder por id de cargo y delega en
 * CulqiPaymentReconciliationService::reconcile(), que consulta la API de Culqi
 * con la llave secreta y valida contexto/importe/moneda antes de actuar.
 */
class ProcessCulqiWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [10, 30, 60, 300, 900];

    public function __construct(public readonly int $paymentWebhookId)
    {
    }

    public function handle(CulqiPaymentReconciliationService $reconciler): void
    {
        $webhook = PaymentWebhook::find($this->paymentWebhookId);
        if (! $webhook) {
            Log::error('[Culqi/Job] payment_webhooks no encontrado — no se puede procesar.', ['payment_webhook_id' => $this->paymentWebhookId]);

            return;
        }

        if (in_array($webhook->status, ['processed', 'review'], true)) {
            return;
        }

        // Evento sobre un recurso que MOVA no gestiona (tokens, clientes, planes, suscripciones…): se registra y se cierra.
        $type = (string) $webhook->event_type;
        if (! str_starts_with($type, 'charge.') && ! str_starts_with($type, 'refund.') && ! str_starts_with($type, 'order.')) {
            $webhook->update(['status' => 'processed', 'processed_at' => now(), 'error' => 'tipo de evento no gestionado']);

            return;
        }

        $chargeId = $this->chargeId($webhook);
        if ($chargeId === null) {
            $reconciler->markReview(null, $webhook, 'Notificación de Culqi sin id de cargo localizable.');

            return;
        }

        $order = PaymentOrder::where('provider', CulqiPaymentProvider::PROVIDER)
            ->where('provider_order_id', $chargeId)
            ->first();

        // Orden de pago aún no vinculada (carrera con la respuesta de creación, o creación incierta): la API
        // dice si su metadata corresponde a un intento de MOVA; solo entonces se vincula y se concilia.
        if (! $order && str_starts_with($chargeId, 'ord_') && $reconciler->linkOrderByMetadata($chargeId, $webhook) !== null) {
            $webhook->refresh();
            if ($webhook->status === 'received') {
                $webhook->update(['status' => 'processed', 'processed_at' => now()]);
            }

            return;
        }

        if (! $order) {
            // Última oportunidad: el cargo no es de MOVA (creado desde el panel/MCP/otro sistema o un
            // evento forjado). Se cierra como ignorado SIN alerta crítica. Si en realidad era una carrera
            // webhook-vs-respuesta, `culqi:reconcile` encontrará la orden por su id de cargo.
            if ($this->attempts() >= $this->tries) {
                $webhook->update(['status' => 'processed', 'processed_at' => now(), 'error' => 'sin PaymentOrder local (cargo ajeno a MOVA)']);
                Log::info('[Culqi/Job] Evento de un cargo sin PaymentOrder local — ignorado.', ['payment_webhook_id' => $webhook->id]);

                return;
            }

            // Antes de eso puede ser una carrera (el POST de creación aún no guardó el id): reintentar.
            throw new RuntimeException("ProcessCulqiWebhook: no existe PaymentOrder local para el cargo {$chargeId} (payment_webhook={$webhook->id}).");
        }

        $reconciler->reconcile($order, $webhook);

        $webhook->refresh();
        if ($webhook->status === 'received') {
            $webhook->update(['status' => 'processed', 'processed_at' => now()]);
        }
    }

    public function failed(Throwable $exception): void
    {
        PaymentWebhook::find($this->paymentWebhookId)?->update([
            'status' => 'failed',
            'error' => substr($exception->getMessage(), 0, 500),
        ]);

        Log::critical('[Culqi/Job] Agotados los reintentos — requiere investigación manual.', [
            'payment_webhook_id' => $this->paymentWebhookId,
            'error' => $exception->getMessage(),
        ]);

        app(OperationalAlertService::class)->raise(
            key: "payment_webhook:{$this->paymentWebhookId}:failed",
            type: OperationalAlert::TYPE_RECONCILIATION_FAILURE,
            title: 'Webhook de Culqi agotó sus reintentos',
            message: 'Una notificación de pago de Culqi no pudo procesarse tras 5 intentos. El pago que describe '
                .'puede no estar reflejado en MOVA. `culqi:reconcile` intentará recuperarlo, pero conviene revisarlo.',
            context: ['PaymentWebhook' => $this->paymentWebhookId, 'Error' => substr($exception->getMessage(), 0, 300)],
            severity: OperationalAlert::SEVERITY_CRITICAL,
        );
    }

    private function chargeId(PaymentWebhook $webhook): ?string
    {
        $payload = $webhook->payload;
        $data = $payload['data'] ?? null;
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        if (! is_array($data)) {
            return null;
        }

        $id = str_starts_with((string) $webhook->event_type, 'refund.') ? ($data['charge_id'] ?? null) : ($data['id'] ?? null);

        return is_string($id) && $id !== '' ? $id : null;
    }
}
