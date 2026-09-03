<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMercadoPagoWebhook;
use App\Payment\MercadoPago\MalformedWebhookPayloadException;
use App\Payment\MercadoPagoPaymentProvider;
use App\Services\PaymentWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint de webhook de Mercado Pago (Payments API — tópico principal
 * `payment`; ver MercadoPagoPaymentProvider::verifyWebhook() para el
 * chequeo de `type`). Vive en routes/api.php — igual que
 * WhatsAppWebhookController, NO necesita ninguna excepción de CSRF:
 * routes/api.php ya corre fuera del grupo de middleware 'web', que es
 * donde vive VerifyCsrfToken. No hay auth de sesión: la única autenticación
 * es la firma x-signature (ver MercadoPagoWebhookSignatureVerifier).
 *
 * Responde rápido a propósito: NUNCA hace el trabajo financiero (consulta
 * server-to-server, acreditación) dentro del ciclo de la request. Solo
 * autentica, persiste/dedupe el evento crudo, y encola
 * ProcessMercadoPagoWebhook — igual filosofía que
 * WhatsAppWebhookController::handle() con Meta, adaptada a que aquí el
 * trabajo pesado (una llamada HTTP saliente más) se mueve a un job en vez
 * de hacerse inline, porque a diferencia de WhatsApp esto sí decide si se
 * mueven créditos reales.
 */
class MercadoPagoWebhookController extends Controller
{
    // Las notificaciones de Orders API son metadatos livianos (id, type,
    // action, data.id) — nunca payloads grandes. Límite generoso pero real
    // como defensa barata contra un POST malicioso o mal dirigido aquí.
    private const MAX_BODY_BYTES = 1_000_000; // 1MB

    public function handle(Request $request, MercadoPagoPaymentProvider $provider, PaymentWebhookService $webhookService): Response
    {
        // WEBHOOK ENABLEMENT (ronda de hardening distribuido): mientras
        // MERCADOPAGO_WEBHOOKS_ENABLED sea false (default), este endpoint
        // NUNCA acepta/procesa nada como si el webhook ya estuviera
        // configurado en el panel de Mercado Pago — ni siquiera una
        // notificación con firma válida (webhook_secret podría estar
        // seteado para pruebas locales sin que el flag esté prendido). No
        // se toca CSRF global ni el registro de la ruta — mismo endpoint,
        // mismo middleware, solo un fail-closed explícito al inicio.
        if (! (bool) config('payments.mercadopago.webhooks_enabled', false)) {
            Log::warning('[MercadoPago/Webhook] Notificación recibida con MERCADOPAGO_WEBHOOKS_ENABLED=false — descartada sin procesar.');

            return response('Not Found', 404);
        }

        $rawPayload = $request->getContent();

        if (strlen($rawPayload) > self::MAX_BODY_BYTES) {
            Log::warning('[MercadoPago/Webhook] Payload rechazado por tamaño.', ['bytes' => strlen($rawPayload)]);

            return response('Payload Too Large', 413);
        }

        $headers = [
            'x-signature' => $request->header('x-signature'),
            'x-request-id' => $request->header('x-request-id'),
            // OJO: la URL de Mercado Pago manda el query param como
            // "data.id" (con punto), pero PHP convierte automáticamente los
            // puntos en los NOMBRES de parámetros de query string a guiones
            // bajos al poblar $_GET — por eso se lee como 'data_id', nunca
            // como 'data.id' (Request::query('data.id') devolvería null
            // siempre). Esto es un comportamiento de PHP, no de Laravel ni
            // de Mercado Pago.
            'data_id_query' => $request->query('data_id'),
        ];

        try {
            $event = $provider->verifyWebhook($rawPayload, $headers);
        } catch (MalformedWebhookPayloadException $e) {
            Log::warning('[MercadoPago/Webhook] Firma válida pero payload malformado — descartado.', [
                'motivo' => $e->getMessage(),
            ]);

            // Sin filtrar el motivo exacto al llamador (sección 12) — 400
            // genérico. La firma sí era válida, así que esto NO es un 401.
            return response('Bad Request', 400);
        }

        if ($event === null) {
            Log::warning('[MercadoPago/Webhook] Firma inválida o secreto no configurado — payload descartado.');

            return response('Unauthorized', 401);
        }

        $webhook = $webhookService->persist('mercadopago', $event);

        if ($webhook->wasRecentlyCreated) {
            // afterCommit(): nunca debe correr antes de que este INSERT
            // haya committeado de verdad (sección 6 del encargo) — no se
            // cambió la configuración global de colas (after_commit en
            // config/queue.php) para esto, se pide explícitamente en el
            // dispatch, que es más seguro y no afecta al resto de la app.
            ProcessMercadoPagoWebhook::dispatch($webhook->id)->afterCommit();
        }

        // 200 idempotente tanto para un evento nuevo (ya encolado) como
        // para un duplicado ya conocido y sano — sección 12 del encargo.
        return response('OK', 200);
    }
}
