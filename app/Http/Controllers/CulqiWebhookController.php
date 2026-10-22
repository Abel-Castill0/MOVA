<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCulqiWebhook;
use App\Payment\Culqi\MalformedCulqiWebhookException;
use App\Payment\CulqiPaymentProvider;
use App\Services\PaymentWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Receptor de webhooks de Culqi (CulqiPanel > Eventos > Webhooks).
 *
 * Culqi no documenta firma ni autenticación de sus notificaciones, así que la
 * URL registrada lleva un secreto largo (CULQI_WEBHOOK_SECRET) como último
 * segmento. Eso solo descarta tráfico ajeno: NADA de lo que diga el cuerpo
 * se toma como verdad — el job consulta la API de Culqi antes de acreditar.
 * Responde 200 rápido y deduplica por UNIQUE(provider, event_id).
 */
class CulqiWebhookController extends Controller
{
    private const MAX_BODY_BYTES = 262_144; // 256 KB: un evento de Culqi pesa unos pocos KB

    public function handle(Request $request, string $token, CulqiPaymentProvider $provider, PaymentWebhookService $webhookService): Response
    {
        if (! (bool) config('payments.culqi.webhooks_enabled', false)) {
            Log::warning('[Culqi/Webhook] Notificación recibida con CULQI_WEBHOOKS_ENABLED=false — descartada sin procesar.');

            return response('Not Found', 404);
        }

        $rawPayload = $request->getContent();
        if (strlen($rawPayload) > self::MAX_BODY_BYTES) {
            Log::warning('[Culqi/Webhook] Payload rechazado por tamaño.', ['bytes' => strlen($rawPayload)]);

            return response('Payload Too Large', 413);
        }

        try {
            $event = $provider->verifyWebhook($rawPayload, ['x-mova-webhook-token' => $token]);
        } catch (MalformedCulqiWebhookException $e) {
            Log::warning('[Culqi/Webhook] Secreto válido pero payload malformado — descartado.', ['motivo' => $e->getMessage()]);

            return response('Bad Request', 400);
        }

        if ($event === null) {
            // Ni el secreto ni el payload se registran.
            Log::warning('[Culqi/Webhook] Secreto de URL inválido o no configurado — descartado.');

            return response('Unauthorized', 401);
        }

        $webhook = $webhookService->persist(CulqiPaymentProvider::PROVIDER, $event);

        if ($webhook->wasRecentlyCreated) {
            ProcessCulqiWebhook::dispatch($webhook->id)->afterCommit();
        }

        return response('OK', 200);
    }
}
