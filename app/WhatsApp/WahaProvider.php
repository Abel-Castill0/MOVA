<?php

namespace App\WhatsApp;

use App\Models\User;
use App\Models\WhatsAppMessage;
use App\WhatsApp\Contracts\WhatsAppProviderContract;
use App\WhatsApp\Waha\WahaClient;
use App\WhatsApp\Waha\WahaMessageId;
use App\WhatsApp\Waha\WahaSessionState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Proveedor WAHA (WhatsApp no oficial) detrás del MISMO contrato que Meta: WhatsAppChannel sigue siendo la única puerta
 * (consentimiento, baja, teléfono verificado, suspensión, cierre de cuenta) y este proveedor añade, en el momento de la
 * entrega, las defensas propias de un número no oficial.
 *
 * Reglas que NO se negocian:
 *  - Nunca lanza: las notificaciones son multicanal (base de datos + correo + WhatsApp) y una excepción aquí haría
 *    reintentar el job y REENVIAR los otros canales. Todo fallo se registra en whatsapp_messages y devuelve false; el
 *    aviso ya salió por correo/campana (ese es el fallback).
 *  - «Aceptado por WAHA» NO es «entregado»: la fila queda en `sent` y solo el webhook message.ack la sube a
 *    delivered/read. Un timeout o un 2xx sin id quedan `unknown` y NO se reintentan (podrían haber salido).
 *  - Idempotencia: el mismo texto al mismo destinatario dentro de la ventana de dedupe se envía una sola vez (un
 *    reintento de cola o un doble disparo no duplican el aviso). El código OTP se exceptúa porque cambia en cada envío.
 *  - Lista autorizada del piloto (WAHA_ALLOWED_RECIPIENTS): si no está vacía, nadie más recibe nada.
 *  - Ritmo humano: tope por minuto global, tope diario por destinatario y simulación de escritura con pausa aleatoria.
 *  - Se detiene ante restricciones de WhatsApp (reachoutTimelock / messageCapping) y con la sesión caída.
 */
final class WahaProvider implements WhatsAppProviderContract
{
    private const TEMPLATE_GENERIC = 'generic_notification';

    private const TEMPLATE_OTP = 'phone_verification_code';

    private const MAX_TEXT = 1500;

    public function __construct(private readonly ?WahaClient $client = null)
    {
    }

    public function sendTemplate(string $to, string $templateKey, array $params, ?string $clientReference = null): bool
    {
        try {
            return $this->deliver($to, $templateKey, $params, $clientReference);
        } catch (\Throwable $e) {
            // Contrato: este proveedor NUNCA lanza (una excepción reintentaría el job y reenviaría los otros canales).
            Log::error('[WhatsApp/WAHA] Error inesperado al enviar: se descarta el envío por WhatsApp.', ['error' => Str::limit($e->getMessage(), 200, ''), 'client_reference' => $clientReference]);

            return false;
        }
    }

    private function deliver(string $to, string $templateKey, array $params, ?string $clientReference): bool
    {
        $text = $this->render($templateKey, $params);
        if ($text === null) {
            $this->recordOnly($to, $templateKey, WhatsAppMessageStatus::Failed, "Plantilla '{$templateKey}' no soportada por el proveedor WAHA", $clientReference);

            return false;
        }

        $digits = WahaClient::digits($to);
        if (strlen($digits) < 10 || strlen($digits) > 15) {
            $this->recordOnly($to, $templateKey, WhatsAppMessageStatus::Failed, 'Número de teléfono no válido', $clientReference);

            return false;
        }

        if ($this->isClosedAccount($to)) {
            $this->skip($digits, $templateKey, WhatsAppSkipReason::AccountClosed, $clientReference);

            return false;
        }

        if (! $this->isAllowed($digits)) {
            $this->skip($digits, $templateKey, WhatsAppSkipReason::NotAllowlisted, $clientReference);

            return false;
        }

        // Protección de la vinculación: WhatsApp revoca el dispositivo de los clientes no oficiales que inician chats con
        // desconocidos (restricción «reachout timelock» + cierre de la sesión). Responder a quien escribió primero es seguro.
        $initiated = WahaSessionState::hasContacted($digits);
        if (! $initiated) {
            $mustInitiate = (bool) config('services.waha.require_user_initiated', true);
            if ($mustInitiate || WahaSessionState::warmupRemainingMinutes() > 0) {
                $this->skip($digits, $templateKey, $mustInitiate ? WhatsAppSkipReason::NotInitiated : WhatsAppSkipReason::WarmingUp, $clientReference);

                return false;
            }
        }

        if (! $initiated && ($until = WahaSessionState::pausedUntil()) !== null) {
            $this->recordOnly($digits, $templateKey, WhatsAppMessageStatus::Failed, 'WhatsApp restringe temporalmente los envíos hasta '.$until->toDateTimeString().' UTC', $clientReference);

            return false;
        }

        $isOtp = $templateKey === self::TEMPLATE_OTP;

        if (! $isOtp && RateLimiter::tooManyAttempts($this->recipientKey($digits), (int) config('services.waha.max_per_recipient_per_day', 12))) {
            $this->skip($digits, $templateKey, WhatsAppSkipReason::RateLimited, $clientReference);

            return false;
        }

        $client = $this->client ?? WahaClient::fromConfig();

        if (! $this->sessionIsWorking($client)) {
            $this->recordOnly($digits, $templateKey, WhatsAppMessageStatus::Failed, 'Sesión de WAHA no disponible (no está en WORKING)', $clientReference);

            return false;
        }

        $dedupeKey = null;
        if (! $isOtp) {
            $dedupeKey = 'waha:dedupe:'.hash('sha256', $digits.'|'.$text);
            if (! Cache::add($dedupeKey, 1, now()->addMinutes((int) config('services.waha.dedupe_minutes', 30)))) {
                Log::info('[WhatsApp/WAHA] Envío duplicado dentro de la ventana de dedupe: omitido.', ['client_reference' => $clientReference]);

                return false;
            }
        }

        if (! $this->acquireGlobalSlot($isOtp)) {
            $this->forget($dedupeKey);
            $this->skip($digits, $templateKey, WhatsAppSkipReason::RateLimited, $clientReference);

            return false;
        }

        // Fila ANTES de enviar: si el proceso muere entre el envío y el registro, queda `unknown` y no se reenvía.
        $row = $this->createRow($digits, $templateKey, $clientReference);
        if ($row === null) {
            $this->forget($dedupeKey);

            return false;
        }

        $chatId = WahaClient::chatId($digits);
        if (! $isOtp) {
            $this->humanPause($client, $chatId);
        }
        $result = $client->sendText($chatId, $text);

        if ($result->isSent()) {
            $row->update([
                'status' => WhatsAppMessageStatus::Sent,
                'provider_message_id' => WahaMessageId::normalize((string) $result->messageId),
                'status_updated_at' => now(),
                'error' => null,
            ]);
            if (! $isOtp) {
                RateLimiter::hit($this->recipientKey($digits), 86400);
            }
            Log::info('[WhatsApp/WAHA] Aceptado por WAHA (la entrega se confirma por webhook).', ['client_reference' => $clientReference]);

            return true;
        }

        if ($result->isFailed()) {
            $this->forget($dedupeKey);
            $row->update(['status' => WhatsAppMessageStatus::Failed, 'status_updated_at' => now(), 'error' => Str::limit((string) $result->error, 500, '')]);

            return false;
        }

        // Incierto: no se sabe si salió. Se conserva la llave de dedupe (no reenviar a ciegas) y la fila queda `unknown`.
        $row->update(['status' => WhatsAppMessageStatus::Unknown, 'status_updated_at' => now(), 'error' => Str::limit((string) $result->error, 500, '')]);
        Log::warning('[WhatsApp/WAHA] Resultado incierto del envío: no se reintenta.', ['client_reference' => $clientReference]);

        return false;
    }

    /** Texto plano de cada plantilla. WAHA no usa plantillas aprobadas por Meta: el texto lo arma MOVA. */
    private function render(string $templateKey, array $params): ?string
    {
        $first = isset($params[0]) ? trim((string) $params[0]) : '';

        return match ($templateKey) {
            self::TEMPLATE_GENERIC => $first === '' ? null : Str::limit($first, self::MAX_TEXT, '…')."\n\nPara dejar de recibir avisos por WhatsApp, desactívalos en tu perfil de MOVA.",
            self::TEMPLATE_OTP => $first === '' ? null : "MOVA — Tu código de verificación es {$first}. Caduca en pocos minutos. No lo compartas con nadie.",
            default => null,
        };
    }

    private function isAllowed(string $digits): bool
    {
        $raw = (string) config('services.waha.allowed_recipients', '');
        if (trim($raw) === '') {
            return true;
        }

        $allowed = array_filter(array_map(fn (string $n) => WahaClient::digits($n), explode(',', $raw)));

        return in_array($digits, $allowed, true);
    }

    /** Defensa en profundidad al entregar: un job puede haberse encolado antes de que la cuenta se cerrara. */
    private function isClosedAccount(string $to): bool
    {
        $normalized = User::normalizePhone($to);

        return $normalized !== null
            && User::whereNotNull('account_closed_at')->where('phone_verified_normalized', $normalized)->exists();
    }

    private function sessionIsWorking(WahaClient $client): bool
    {
        $status = WahaSessionState::status(120);

        if ($status === null) {
            $live = $client->sessionStatus();
            $status = $live['ok'] ? $live['status'] : null;
            WahaSessionState::record($status);
        }

        return $status === 'WORKING';
    }

    /** Hasta ~20 s de espera por un hueco del límite por minuto; después se descarta (el correo ya cubre el aviso). */
    private function acquireGlobalSlot(bool $noWait = false): bool
    {
        $max = max(1, (int) config('services.waha.rate_per_minute', 8));
        // El OTP se envía dentro de la petición del usuario: nunca espera un hueco ni ocupa un worker PHP.
        $waitSeconds = $noWait ? 0 : max(0, (int) config('services.waha.rate_wait_seconds', 20));

        for ($i = 0; $i <= $waitSeconds; $i++) {
            if (RateLimiter::attempt('waha:global', $max, fn () => true, 60)) {
                return true;
            }

            if ($i < $waitSeconds) {
                usleep(1_000_000);
            }
        }

        return false;
    }

    private function humanPause(WahaClient $client, string $chatId): void
    {
        $min = max(0, (int) config('services.waha.typing_min_ms', 1200));
        $max = max($min, (int) config('services.waha.typing_max_ms', 3200));

        if ($max === 0) {
            return;
        }

        $client->typing($chatId, true);
        usleep(random_int($min, $max) * 1000);
        $client->typing($chatId, false);
    }

    private function recipientKey(string $digits): string
    {
        return 'waha:rcpt:'.$digits;
    }

    private function forget(?string $key): void
    {
        if ($key !== null) {
            Cache::forget($key);
        }
    }

    private function skip(string $to, string $templateKey, WhatsAppSkipReason $reason, ?string $clientReference): void
    {
        $this->persist($to, $templateKey, WhatsAppMessageStatus::Skipped, null, $clientReference, $reason);
    }

    private function recordOnly(string $to, string $templateKey, WhatsAppMessageStatus $status, string $error, ?string $clientReference): void
    {
        $this->persist($to, $templateKey, $status, $error, $clientReference, null);
    }

    private function createRow(string $to, string $templateKey, ?string $clientReference): ?WhatsAppMessage
    {
        try {
            return WhatsAppMessage::create([
                'to' => $to,
                'template_key' => $templateKey,
                'client_reference' => $clientReference,
                'provider' => 'waha',
                'provider_message_id' => null,
                'status' => WhatsAppMessageStatus::Unknown,
                'status_updated_at' => now(),
                'error' => 'Envío en curso',
            ]);
        } catch (\Throwable $e) {
            Log::error('[WhatsApp/WAHA] No se pudo registrar el intento: no se envía (sin auditoría no se envía).', ['error' => get_class($e)]);

            return null;
        }
    }

    private function persist(string $to, string $templateKey, WhatsAppMessageStatus $status, ?string $error, ?string $clientReference, ?WhatsAppSkipReason $reason): void
    {
        try {
            WhatsAppMessage::create([
                'to' => $to,
                'template_key' => $templateKey,
                'client_reference' => $clientReference,
                'provider' => 'waha',
                'provider_message_id' => null,
                'status' => $status,
                'status_updated_at' => now(),
                'skip_reason' => $reason,
                'error' => $error ? Str::limit($error, 500, '') : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[WhatsApp/WAHA] No se pudo registrar el intento.', ['error' => get_class($e)]);
        }
    }
}
