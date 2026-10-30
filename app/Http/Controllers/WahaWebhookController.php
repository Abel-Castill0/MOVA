<?php

namespace App\Http\Controllers;

use App\WhatsApp\Waha\WahaMessageId;
use App\WhatsApp\Waha\WahaSessionState;
use App\WhatsApp\WhatsAppMessageStatus;
use App\WhatsApp\WhatsAppStatusRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Webhook de WAHA: confirmación de entrega (`message.ack`) y estado de la sesión (`session.status`).
 *
 * Autenticación: WAHA firma el CUERPO BRUTO con HMAC-SHA512 y envía `X-Webhook-Hmac` (hex) y
 * `X-Webhook-Hmac-Algorithm: sha512`. Sin WAHA_HOOK_HMAC_KEY configurada el endpoint rechaza todo (no hay modo de
 * que acepte tráfico sin verificar solo porque la ruta exista). Se comprueba además `X-Webhook-Timestamp` (ms) contra una
 * tolerancia para acotar el replay; los eventos repetidos ya son idempotentes (WhatsAppStatusRecorder).
 *
 * Esta ruta SOLO actualiza el estado de entrega de mensajes que MOVA envió (correlación por id de mensaje). No procesa
 * mensajes entrantes, no toca créditos, pagos ni colas financieras.
 *
 * Mapeo de `ackName` → estado de whatsapp_messages: ERROR → failed; SERVER → sent (ya registrado al enviar);
 * DEVICE → delivered; READ/PLAYED → read; PENDING → ignorado.
 */
class WahaWebhookController extends Controller
{
    private const MAX_BODY_BYTES = 262_144; // 256 KB

    public function handle(Request $request): Response
    {
        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return response('Payload Too Large', 413);
        }

        if (! $this->hasValidSignature($request)) {
            Log::warning('[WhatsApp/WAHA/Webhook] Firma inválida, ausente o clave no configurada — payload descartado.');

            return response('Forbidden', 403);
        }

        if (! $this->isFresh($request)) {
            Log::warning('[WhatsApp/WAHA/Webhook] Marca de tiempo fuera de tolerancia — payload descartado.');

            return response('Forbidden', 403);
        }

        $payload = $request->json()->all();
        $event = (string) ($payload['event'] ?? '');

        match ($event) {
            'message.ack' => $this->handleAck($payload),
            'session.status' => $this->handleSessionStatus($payload),
            default => null,
        };

        return response('OK', 200);
    }

    private function hasValidSignature(Request $request): bool
    {
        $key = (string) config('services.waha.hmac_key', '');
        $header = (string) $request->header('X-Webhook-Hmac', '');
        $algorithm = strtolower((string) $request->header('X-Webhook-Hmac-Algorithm', 'sha512'));

        if ($key === '' || $header === '' || $algorithm !== 'sha512') {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $request->getContent(), $key), strtolower($header));
    }

    private function isFresh(Request $request): bool
    {
        $timestamp = $request->header('X-Webhook-Timestamp');
        if ($timestamp === null || ! is_numeric($timestamp)) {
            return true; // la firma ya es la garantía; la marca de tiempo es una defensa adicional si WAHA la envía
        }

        $tolerance = (int) config('services.waha.webhook_tolerance_seconds', 300);

        return abs(now()->getTimestamp() - (int) round(((float) $timestamp) / 1000)) <= $tolerance;
    }

    private function handleAck(array $payload): void
    {
        $data = (array) ($payload['payload'] ?? []);

        // Solo los mensajes que ENVIÓ MOVA; los acks de mensajes ajenos no son nuestros.
        if (($data['fromMe'] ?? true) === false) {
            return;
        }

        $rawId = $data['id'] ?? null;
        $ackName = strtoupper((string) ($data['ackName'] ?? ''));
        if (! is_string($rawId) || $rawId === '' || $ackName === '') {
            return;
        }

        $status = match ($ackName) {
            'ERROR' => WhatsAppMessageStatus::Failed,
            'DEVICE' => WhatsAppMessageStatus::Delivered,
            'READ', 'PLAYED' => WhatsAppMessageStatus::Read,
            default => null, // PENDING y SERVER: «sent» ya se registró al enviar
        };

        if ($status === null) {
            return;
        }

        $messageId = WahaMessageId::normalize($rawId);
        $eventAt = isset($payload['timestamp']) && is_numeric($payload['timestamp'])
            ? Carbon::createFromTimestampMs((int) $payload['timestamp'])
            : now();

        app(WhatsAppStatusRecorder::class)->record(
            'waha',
            $messageId,
            $status,
            $eventAt,
            hash('sha256', "waha:{$messageId}:{$status->value}"),
        );
    }

    private function handleSessionStatus(array $payload): void
    {
        $data = (array) ($payload['payload'] ?? []);
        $status = isset($data['status']) ? (string) $data['status'] : null;

        WahaSessionState::record($status);

        if ($status === 'FAILED' || $status === 'STOPPED') {
            Log::warning('[WhatsApp/WAHA] La sesión dejó de estar operativa; los envíos se omiten hasta que vuelva a WORKING.', ['status' => $status]);
        }

        $extra = (array) ($data['data'] ?? $payload['data'] ?? []);

        $timelock = (array) ($extra['reachoutTimelock'] ?? []);
        if (($timelock['isActive'] ?? false) === true) {
            $until = isset($timelock['timeEnforcementEnds']) && is_numeric($timelock['timeEnforcementEnds'])
                ? Carbon::createFromTimestamp((int) $timelock['timeEnforcementEnds'])
                : now()->addHours(6);
            WahaSessionState::pauseUntil($until);
            Log::warning('[WhatsApp/WAHA] WhatsApp aplicó una restricción de contacto nuevo (reachoutTimelock): envíos en pausa.', ['hasta' => $until->toDateTimeString()]);
        }

        $capping = (array) ($extra['messageCapping'] ?? []);
        if (strtoupper((string) ($capping['cappingStatus'] ?? '')) === 'CAPPED') {
            $until = isset($capping['cycleEnd']) && is_numeric($capping['cycleEnd'])
                ? Carbon::createFromTimestamp((int) $capping['cycleEnd'])
                : now()->addHours(12);
            WahaSessionState::pauseUntil($until);
            Log::warning('[WhatsApp/WAHA] Cupo de contactos nuevos agotado (messageCapping): envíos en pausa.', ['hasta' => $until->toDateTimeString()]);
        }
    }
}
