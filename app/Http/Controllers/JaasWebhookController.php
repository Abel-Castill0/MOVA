<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\LessonPresenceEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Receptor de los webhooks de JaaS (8x8) de presencia: PARTICIPANT_JOINED y
 * PARTICIPANT_LEFT.
 *
 * ALCANCE (deliberadamente acotado): guarda evidencia en lesson_presence_events.
 * NO cambia el estado de la clase, ni créditos, ni liquidación, ni notifica a
 * nadie: las reglas de asistencia/ausencia/disputa no están definidas
 * (ledger C-P1-ATTENDANCE-DISPUTES). Que un participante "entró" según JaaS no
 * equivale a asistencia.
 *
 * Autenticación (ver config/jaas.php): firma `X-Jaas-Signature` HMAC-SHA256 con
 * el signing secret de JaaS (JAAS_WEBHOOK_SIGNING_SECRET, recomendada) y/o el
 * header `Authorization` estático opcional que se define en la consola de JaaS
 * (JAAS_WEBHOOK_AUTH_TOKEN, secreto DISTINTO). Sin ninguno configurado, o con
 * credenciales que no coinciden, responde 401. Aun autenticado, el endpoint solo
 * guarda datos acotados e idempotentes y no ejecuta ninguna acción de negocio.
 *
 * Desactivado por defecto (JAAS_WEBHOOKS_ENABLED=false → 404).
 *
 * Idempotencia: `idempotencyKey` de JaaS es único en BD; un reintento responde
 * 200 sin duplicar. Respuestas: 2xx para todo lo que se entendió (incluidos los
 * eventos que se ignoran a propósito, para que JaaS no reintente en vano); 4xx
 * solo para autenticación y cuerpo inválido.
 */
class JaasWebhookController extends Controller
{
    private const MAX_BODY_BYTES = 100_000;

    private const HANDLED = [
        'PARTICIPANT_JOINED' => LessonPresenceEvent::JOINED,
        'PARTICIPANT_LEFT' => LessonPresenceEvent::LEFT,
    ];

    public function handle(Request $request): Response
    {
        if (! config('jaas.webhooks_enabled')) {
            return response('Not Found', 404);
        }

        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return response('Payload Too Large', 413);
        }

        if (! $this->isAuthentic($request)) {
            return response('Unauthorized', 401);
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)
            || ! is_string($payload['idempotencyKey'] ?? null)
            || ! is_string($payload['eventType'] ?? null)
            || ! is_string($payload['fqn'] ?? null)
            || ! is_numeric($payload['timestamp'] ?? null)) {
            return response('Bad Request', 400);
        }

        $type = self::HANDLED[$payload['eventType']] ?? null;
        if ($type === null) {
            return response('OK', 200); // evento que MOVA no usa: se reconoce y se descarta
        }

        // fqn = "<AppID>/<sala>": debe ser NUESTRO tenant y una sala conocida.
        [$appId, $room] = array_pad(explode('/', $payload['fqn'], 2), 2, '');
        if ($appId === '' || $appId !== (string) config('jaas.app_id') || $room === '') {
            return response('OK', 200);
        }

        $lesson = Lesson::where('jitsi_room', $room)->with(['teacherProfile', 'student'])->first();
        if (! $lesson) {
            Log::info('[JaaS/Webhook] Sala desconocida — evento descartado.', ['event' => $payload['eventType']]);

            return response('OK', 200);
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        try {
            LessonPresenceEvent::create([
                'lesson_id' => $lesson->id,
                'user_id' => $this->participantUserId($lesson, $data['id'] ?? null),
                'event_type' => $type,
                'is_moderator' => filter_var($data['moderator'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'disconnect_reason' => $type === LessonPresenceEvent::LEFT
                    ? $this->limit($data['disconnectReason'] ?? null, 32)
                    : null,
                'session_id' => $this->limit($payload['sessionId'] ?? null, 128),
                'participant_ref' => $this->limit($data['participantId'] ?? null, 128),
                'external_event_id' => $this->limit($payload['idempotencyKey'], 128),
                'occurred_at' => $this->occurredAt($payload['timestamp']),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Reintento de JaaS de un evento ya guardado.
        }

        return response('OK', 200);
    }

    /**
     * Fail closed: sin ningún mecanismo configurado no se acepta nada. Con los dos
     * configurados se exigen los dos.
     */
    private function isAuthentic(Request $request): bool
    {
        $signingSecret = (string) config('jaas.webhook_signing_secret');
        $authToken = (string) config('jaas.webhook_auth_token');

        if ($signingSecret === '' && $authToken === '') {
            return false;
        }

        if ($authToken !== '' && ! hash_equals('Bearer '.$authToken, (string) $request->header('Authorization', ''))) {
            return false;
        }

        return $signingSecret === '' || $this->signatureIsValid($request, $signingSecret);
    }

    /**
     * X-Jaas-Signature: t=<unix>,v1=<base64(HMAC-SHA256("<t>.<cuerpo crudo>", secreto))>
     * (https://developer.8x8.com/jaas/docs/webhooks-signatures). Puede traer varias
     * firmas v1 (rotación de secreto); cualquier otro esquema se descarta para evitar
     * ataques de degradación.
     */
    private function signatureIsValid(Request $request, string $secret): bool
    {
        $header = (string) $request->header('X-Jaas-Signature', '');
        if ($header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            // Solo se divide en el PRIMER '=': el base64 termina en '='.
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        // JaaS usa segundos; se tolera milisegundos por si cambia.
        $seconds = $timestamp > 1.0e11 ? intdiv($timestamp, 1000) : $timestamp;
        if (abs(time() - $seconds) > (int) config('jaas.webhook_tolerance_seconds', 600)) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret, true));
        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * El id viene del JWT que EMITIÓ MOVA (context.user.id). Solo se atribuye si
     * es el docente o el padre de ESA clase; cualquier otro valor queda sin
     * usuario en vez de forzar una atribución dudosa.
     */
    private function participantUserId(Lesson $lesson, mixed $id): ?int
    {
        if (! is_string($id) && ! is_int($id)) {
            return null;
        }
        if (! ctype_digit((string) $id)) {
            return null;
        }

        $userId = (int) $id;
        $allowed = [$lesson->teacherProfile?->user_id, $lesson->student?->parent_user_id];

        return in_array($userId, $allowed, true) ? $userId : null;
    }

    /** JaaS envía un timestamp Unix; se acepta en segundos o milisegundos. */
    private function occurredAt(mixed $timestamp): Carbon
    {
        $value = (float) $timestamp;

        return $value > 1.0e11
            ? Carbon::createFromTimestampMs((int) $value)
            : Carbon::createFromTimestamp((int) $value);
    }

    private function limit(mixed $value, int $max): ?string
    {
        return is_scalar($value) && $value !== '' ? mb_substr((string) $value, 0, $max) : null;
    }
}
