<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppMessage;
use App\Models\WhatsAppWebhookEvent;
use App\WhatsApp\WhatsAppMessageStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

        if (! $this->recordNewEvent($messageId, $newStatus, $status['timestamp'] ?? null)) {
            Log::info('[WhatsApp/Webhook] Entrega de evento duplicada (Meta reenvió el mismo evento) — ignorada.', [
                'id' => $messageId,
                'status' => $newStatus->value,
            ]);

            return;
        }

        DB::transaction(function () use ($messageId, $newStatus, $eventAt) {
            $message = WhatsAppMessage::where('provider', 'meta')
                ->where('provider_message_id', $messageId)
                ->lockForUpdate()
                ->first();

            if (! $message) {
                Log::info('[WhatsApp/Webhook] Estado para un mensaje no registrado localmente.', ['id' => $messageId]);

                return;
            }

            if (! $this->shouldApply($message->status, $newStatus)) {
                Log::info('[WhatsApp/Webhook] Evento fuera de orden ignorado (no retrocede el estado).', [
                    'id' => $messageId,
                    'estado_actual' => $message->status->value,
                    'evento_recibido' => $newStatus->value,
                ]);

                return;
            }

            $update = ['status' => $newStatus, 'status_updated_at' => $eventAt];
            if ($newStatus === WhatsAppMessageStatus::Delivered) {
                $update['delivered_at'] = $eventAt;
            } elseif ($newStatus === WhatsAppMessageStatus::Read) {
                $update['read_at'] = $eventAt;
            }

            $message->update($update);
        });
    }

    /**
     * true si es la primera vez que se ve este evento exacto (wamid +
     * status + timestamp) — false si ya se procesó antes (reintento de
     * entrega del webhook). Meta no expone un id de evento propio en los
     * webhooks de estado, así que el hash de estos tres campos ES el
     * identificador del evento en la práctica.
     */
    private function recordNewEvent(string $messageId, WhatsAppMessageStatus $status, mixed $timestamp): bool
    {
        $eventKey = hash('sha256', "{$messageId}:{$status->value}:{$timestamp}");

        try {
            WhatsAppWebhookEvent::create([
                'event_key' => $eventKey,
                'provider_message_id' => $messageId,
                'status' => $status->value,
                'received_at' => now(),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * Regla de avance: 'failed' es terminal PARA ESTE WhatsAppMessage (un
     * intento de envío concreto) — no para el evento de negocio que lo
     * originó. Nada impide que MOVA envíe un WhatsAppMessage #2 más
     * adelante para el mismo recordatorio/confirmación si la política de
     * negocio lo permite; eso simplemente sería otra fila nueva, no una
     * reapertura de esta. 'failed' entrante solo se aplica si el mensaje
     * no llegó ya a un estado de entrega real (delivered/read) — Meta no
     * debería mandar 'failed' después de eso, pero si llegara, ignorarlo
     * es lo seguro. Para sent/delivered/read: solo avanza si el rango
     * nuevo es estrictamente mayor al actual (o el actual es 'unknown',
     * que no tiene rango propio y siempre puede ser reemplazado por un
     * evento real) — el timestamp del evento NUNCA decide esto por sí
     * solo (un 'delivered' con timestamp más nuevo que un 'read' ya
     * aplicado sigue sin poder retroceder el estado; ver
     * test_out_of_order_delivery_timestamps_do_not_regress_status).
     *
     * 'skipped' es terminal por la misma razón que 'failed', y se protege
     * con el mismo guard explícito — NO dejarlo caer en el cálculo de rango
     * de más abajo: ahí deliveryRank() ?? 0 trata cualquier estado sin rango
     * propio como "siempre superable", que es lo correcto para 'unknown'
     * (un resultado incierto que un evento real debe poder resolver) pero
     * sería incorrecto para 'skipped' (una decisión ya tomada por MOVA, no
     * un resultado pendiente). En la práctica esta fila nunca es alcanzable
     * por un webhook real porque provider_message_id es NULL en todo
     * mensaje 'skipped' — pero ese hecho no debería ser lo único que evita
     * la regresión; ver test_skipped_does_not_advance_to_delivered/read.
     */
    private function shouldApply(WhatsAppMessageStatus $current, WhatsAppMessageStatus $incoming): bool
    {
        if ($current === WhatsAppMessageStatus::Failed || $current === WhatsAppMessageStatus::Skipped) {
            return false;
        }

        if ($incoming === WhatsAppMessageStatus::Failed) {
            return ! in_array($current, [WhatsAppMessageStatus::Delivered, WhatsAppMessageStatus::Read], true);
        }

        $currentRank = $current->deliveryRank() ?? 0; // Unknown también entra aquí, siempre superable
        $incomingRank = $incoming->deliveryRank();

        return $incomingRank !== null && $incomingRank > $currentRank;
    }
}
