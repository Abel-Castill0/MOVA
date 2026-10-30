<?php

namespace App\Http\Controllers;

use App\WhatsApp\WhatsAppMessageStatus;
use App\WhatsApp\WhatsAppStatusRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint de webhook de Meta — diseñado pero deliberadamente INERTE hasta
 * que exista una cuenta real de Meta Business (ver
 * docs/whatsapp-architecture.md). Sin META_WHATSAPP_WEBHOOK_VERIFY_TOKEN o
 * META_WHATSAPP_APP_SECRET configurados, ambos métodos rechazan todo por
 * diseño — no hay ningún modo en el que esta ruta acepte tráfico no
 * verificado solo porque exista.
 *
 * No procesa mensajes entrantes en profundidad todavía (ventana de servicio
 * de 24h, conversaciones) — solo actualiza el estado de entrega
 * (sent/delivered/read/failed) de whatsapp_messages ya enviados por MOVA,
 * que es lo que soporte necesita para responder "¿le llegó al profesor?".
 *
 * Correlación: SIEMPRE por provider_message_id (wamid), nunca por
 * client_reference — este último no es único (dos envíos del mismo tipo al
 * mismo destinatario lo comparten), así que jamás debe usarse para intentar
 * localizar la fila de un evento entrante. Una fila 'unknown' o un 'sent'
 * sin id (ver F-23) es, por diseño, permanentemente inalcanzable por esta
 * vía — ningún wamid real puede igualar a NULL en la consulta de abajo.
 */
class WhatsAppWebhookController extends Controller
{
    /**
     * Meta llama esto UNA vez al configurar el webhook en el panel — debe
     * responder exactamente hub_challenge si hub_verify_token coincide.
     */
    public function verify(Request $request): Response
    {
        $configuredToken = config('services.meta_whatsapp.webhook_verify_token');

        if (
            $configuredToken
            && $request->query('hub_mode') === 'subscribe'
            && hash_equals($configuredToken, (string) $request->query('hub_verify_token'))
        ) {
            return response((string) $request->query('hub_challenge'), 200);
        }

        Log::warning('[WhatsApp/Webhook] Verificación rechazada (token no coincide o no configurado).');

        return response('Forbidden', 403);
    }

    /**
     * Eventos reales de Meta (statuses/messages). Siempre responde 200
     * rápido si la firma es válida — Meta reintenta agresivamente si no
     * recibe 2xx a tiempo, y un 200 tardío por procesamiento pesado
     * generaría reintentos y duplicados innecesarios.
     */
    // Meta nunca manda payloads grandes (JSON de metadatos, sin adjuntos) —
    // un límite generoso pero real es defensa barata contra un POST
    // malicioso o mal configurado apuntando aquí.
    private const MAX_BODY_BYTES = 1_000_000; // 1MB

    public function handle(Request $request): Response
    {
        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            Log::warning('[WhatsApp/Webhook] Payload rechazado por tamaño.', ['bytes' => strlen($request->getContent())]);

            return response('Payload Too Large', 413);
        }

        if (! $this->hasValidSignature($request)) {
            Log::warning('[WhatsApp/Webhook] Firma inválida o secreto no configurado — payload descartado.');

            return response('Forbidden', 403);
        }

        $payload = $request->json()->all();

        foreach ($this->extractStatuses($payload) as $status) {
            $this->recordStatus($status);
        }

        return response('OK', 200);
    }

    private function hasValidSignature(Request $request): bool
    {
        $appSecret = config('services.meta_whatsapp.app_secret');
        $signatureHeader = $request->header('X-Hub-Signature-256');

        if (! $appSecret || ! $signatureHeader) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);

        return hash_equals($expected, $signatureHeader);
    }

    /**
     * Payload real de Meta: {entry: [{changes: [{value: {statuses: [...]}}]}]}
     * — se navega defensivamente porque el formato exacto solo se puede
     * confirmar contra tráfico real, no contra documentación de terceros
     * (ver docs/whatsapp-architecture.md).
     */
    private function extractStatuses(array $payload): array
    {
        $statuses = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['statuses'] ?? [] as $status) {
                    $statuses[] = $status;
                }
            }
        }

        return $statuses;
    }

    /**
     * Meta advierte explícitamente que los eventos de estado pueden no
     * llegar en el mismo orden en que ocurrieron — por eso esto nunca
     * aplica un evento "a ciegas": compara contra el estado actual bajo
     * lock (dos entregas casi simultáneas del webhook para el mismo
     * mensaje no deben pisarse) y solo avanza el ciclo sent→delivered→read,
     * nunca lo retrocede. Ver WhatsAppMessageStatus::deliveryRank().
     *
     * Dos capas de idempotencia, no una: primero se descarta un reintento
     * de ENTREGA del webhook (Meta reenvía el mismo evento si no recibió
     * 200 a tiempo) vía whatsapp_webhook_events — UNIQUE a nivel de BD, no
     * solo un chequeo en código. Después, incluso si el evento fuera
     * genuinamente nuevo, la regla de avance de más abajo sigue
     * protegiendo contra aplicarlo si ya quedó superado por uno más
     * avanzado. Son capas independientes a propósito, mismo principio que
     * idempotency_key en credit_transactions + el chequeo de estado previo.
     */
    private function recordStatus(array $status): void
    {
        $messageId = $status['id'] ?? null;
        $newStatusValue = $status['status'] ?? null; // sent|delivered|read|failed
        $newStatus = WhatsAppMessageStatus::tryFrom((string) $newStatusValue);

        if (! $messageId || ! $newStatus || $newStatus === WhatsAppMessageStatus::Sent) {
            return; // 'sent' ya se registró al enviar; valores desconocidos se ignoran
        }

        $eventAt = isset($status['timestamp']) && is_numeric($status['timestamp'])
            ? Carbon::createFromTimestamp((int) $status['timestamp'])
            : now();

        // Lógica común de todos los proveedores (dedupe, correlación por id, sin retroceso): WhatsAppStatusRecorder.
        $eventKey = hash('sha256', "{$messageId}:{$newStatus->value}:".($status['timestamp'] ?? ''));
        app(WhatsAppStatusRecorder::class)->record('meta', $messageId, $newStatus, $eventAt, $eventKey);
    }
}
