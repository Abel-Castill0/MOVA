<?php

namespace App\WhatsApp\Waha;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente HTTP mínimo de WAHA (https://waha.devlike.pro). NO decide nada de negocio: el consentimiento, la baja, la
 * suspensión, el cierre de cuenta y la idempotencia viven en WhatsAppChannel y WahaProvider. Aquí solo hay transporte.
 *
 * Seguridad del transporte:
 *  - La API de WAHA nunca se expone a Internet: solo se habla con ella por HTTPS o por una IP PRIVADA (red virtual
 *    emparejada). En producción una URL http hacia un host público es un error de configuración y se rechaza.
 *  - La API key viaja en la cabecera `X-Api-Key` y no se registra jamás en logs ni en mensajes de error.
 *  - Las operaciones destructivas (logout/restart) son métodos explícitos que solo llama el panel de administración
 *    mediante POST; consultar el estado es de SOLO LECTURA (antes, abrir el panel podía desvincular el número).
 */
final class WahaClient
{
    private readonly string $baseUrl;

    public function __construct(
        ?string $baseUrl = null,
        private readonly ?string $apiKey = null,
        private readonly string $session = 'default',
        private readonly int $timeoutSeconds = 12,
    ) {
        $url = rtrim((string) ($baseUrl ?? ''), '/');
        self::assertSafeBaseUrl($url);
        $this->baseUrl = $url;
    }

    public static function fromConfig(): self
    {
        return new self(
            (string) config('services.waha.url'),
            config('services.waha.api_key'),
            (string) config('services.waha.session', 'default'),
            (int) config('services.waha.timeout_seconds', 12),
        );
    }

    /** Número en dígitos internacionales (sin +) → chatId de WAHA. */
    public static function chatId(string $digits): string
    {
        return $digits.'@c.us';
    }

    /** Solo dígitos; un móvil peruano de 9 dígitos recibe el prefijo 51. */
    public static function digits(string $phone): string
    {
        $cleaned = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($cleaned, '00')) {
            $cleaned = substr($cleaned, 2);
        }
        if (strlen($cleaned) === 9) {
            $cleaned = '51'.$cleaned;
        }

        return $cleaned;
    }

    public function sendText(string $chatId, string $text): WahaSendResult
    {
        try {
            $response = $this->http()->post($this->baseUrl.'/api/sendText', [
                'session' => $this->session,
                'chatId' => $chatId,
                'text' => $text,
                'linkPreview' => false,
            ]);
        } catch (\Throwable $e) {
            // Sin respuesta (o fallo inesperado del transporte): el mensaje PUDO haber salido. El proveedor lo trata como incierto y NO reintenta.
            return WahaSendResult::uncertain('Sin respuesta de WAHA: '.$this->safe($e->getMessage()));
        }

        if ($response->serverError()) {
            // 5xx: WAHA pudo haber enviado antes de fallar; es incierto, no un fallo definitivo (no se reenvía a ciegas).
            return WahaSendResult::uncertain('WAHA respondió HTTP '.$response->status(), $response->status());
        }

        if (! $response->successful()) {
            return WahaSendResult::failed($response->status(), 'WAHA respondió HTTP '.$response->status());
        }

        $id = self::extractMessageId($response->json());

        return $id === null
            ? WahaSendResult::uncertain('WAHA respondió 2xx sin id de mensaje', $response->status())
            : WahaSendResult::sent($id, $response->status());
    }

    /** Best-effort: simular escritura es una recomendación anti-bloqueo; un fallo aquí nunca impide el envío. */
    public function typing(string $chatId, bool $on): void
    {
        try {
            $this->http()->timeout(5)->post($this->baseUrl.'/api/'.($on ? 'startTyping' : 'stopTyping'), [
                'session' => $this->session,
                'chatId' => $chatId,
            ]);
        } catch (\Throwable) {
        }
    }

    /** SOLO LECTURA. @return array{ok: bool, status: ?string, me: ?array, data: ?array, error: ?string} */
    public function sessionStatus(): array
    {
        try {
            $response = $this->http()->timeout(8)->get($this->baseUrl.'/api/sessions/'.rawurlencode($this->session));
        } catch (\Throwable $e) {
            $kind = $e instanceof ConnectionException ? 'unreachable' : 'error';

            return ['ok' => false, 'status' => null, 'me' => null, 'data' => null, 'error' => $this->safe($e->getMessage()), 'kind' => $kind];
        }

        if (! $response->successful()) {
            $kind = in_array($response->status(), [401, 403], true) ? 'auth' : ($response->serverError() ? 'server' : 'http');

            return ['ok' => false, 'status' => null, 'me' => null, 'data' => null, 'error' => 'WAHA respondió HTTP '.$response->status(), 'kind' => $kind];
        }

        $data = (array) $response->json();

        return ['ok' => true, 'status' => $data['status'] ?? null, 'me' => $data['me'] ?? null, 'data' => $data, 'error' => null, 'kind' => null];
    }

    /**
     * QR de vinculación como PNG VÁLIDO. Nunca devuelve JSON/HTML de error como si fuera imagen: o bytes con la firma PNG, o una
     * causa legible. WAHA responde la imagen en binario o, con Accept JSON, {mimetype, data(base64)}; se aceptan ambas.
     *
     * @return array{png: ?string, reason: ?string}
     */
    public function qr(): array
    {
        try {
            $response = Http::withHeaders($this->apiKey ? ['X-Api-Key' => $this->apiKey] : [])
                ->accept('image/png, application/json')
                ->withoutRedirecting()
                ->timeout(10)
                ->get($this->baseUrl.'/api/'.rawurlencode($this->session).'/auth/qr', ['format' => 'image']);
        } catch (\Throwable) {
            return ['png' => null, 'reason' => 'WAHA no responde.'];
        }

        if ($response->status() === 404 || $response->status() === 422) {
            return ['png' => null, 'reason' => 'WAHA no tiene un QR disponible: la sesión no está esperando vinculación.'];
        }

        if (! $response->successful()) {
            return ['png' => null, 'reason' => 'WAHA respondió HTTP '.$response->status().' al pedir el QR.'];
        }

        $body = $response->body();
        if (str_contains((string) $response->header('Content-Type'), 'json')) {
            $data = $response->json('data');
            $body = is_string($data) ? (string) base64_decode($data, true) : '';
        }

        // Firma PNG (89 50 4E 47) y tamaño razonable: un QR pesa unos pocos KB.
        if (strlen($body) < 100 || strlen($body) > 300_000 || ! str_starts_with($body, chr(0x89).'PNG')) {
            return ['png' => null, 'reason' => 'WAHA devolvió una respuesta que no es una imagen PNG válida.'];
        }

        return ['png' => $body, 'reason' => null];
    }

    /**
     * Código de emparejamiento de 8 caracteres (alternativa al QR). Solo bajo solicitud explícita; el código NO se registra.
     *
     * @return array{code: ?string, reason: ?string}
     */
    public function requestPairingCode(string $digits): array
    {
        try {
            $response = $this->http()->timeout(20)->post($this->baseUrl.'/api/'.rawurlencode($this->session).'/auth/request-code', ['phoneNumber' => $digits]);
        } catch (\Throwable) {
            return ['code' => null, 'reason' => 'WAHA no responde.'];
        }

        $code = $response->successful() ? $response->json('code') : null;

        return is_string($code) && $code !== ''
            ? ['code' => $code, 'reason' => null]
            : ['code' => null, 'reason' => 'WAHA no pudo generar el código (la sesión debe estar esperando vinculación). HTTP '.$response->status().'.'];
    }

    /** PNG del QR de vinculación (solo si la sesión está en SCAN_QR_CODE). Devuelve bytes o null. */
    public function qrPng(): ?string
    {
        try {
            $response = $this->http()->timeout(10)->get($this->baseUrl.'/api/'.rawurlencode($this->session).'/auth/qr', ['format' => 'image']);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json('data');

        return is_string($data) ? (base64_decode($data, true) ?: null) : null;
    }

    public function startSession(): bool
    {
        return $this->post('/api/sessions/'.rawurlencode($this->session).'/start');
    }

    public function restartSession(): bool
    {
        return $this->post('/api/sessions/'.rawurlencode($this->session).'/restart');
    }

    /** Desvincula el teléfono: SOLO desde una acción explícita de un administrador. */
    public function logoutSession(): bool
    {
        return $this->post('/api/sessions/'.rawurlencode($this->session).'/logout');
    }

    /** @param  mixed  $json */
    public static function extractMessageId($json): ?string
    {
        if (! is_array($json)) {
            return null;
        }

        foreach (['id', 'messageId', 'key.id'] as $path) {
            $value = data_get($json, $path);
            if (is_array($value)) {
                $value = $value['_serialized'] ?? $value['id'] ?? null;
            }
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function post(string $path): bool
    {
        try {
            return $this->http()->timeout(15)->post($this->baseUrl.$path)->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function http(): PendingRequest
    {
        // Sin seguir redirecciones: Guzzle reenviaría X-Api-Key a otro host.
        $request = Http::acceptJson()->asJson()->withoutRedirecting()->timeout($this->timeoutSeconds);

        return $this->apiKey ? $request->withHeaders(['X-Api-Key' => $this->apiKey]) : $request;
    }

    /** Quita la API key y cualquier URL con credenciales de un mensaje de error antes de registrarlo. */
    private function safe(string $message): string
    {
        $message = $this->apiKey ? str_replace($this->apiKey, '[REDACTADO]', $message) : $message;

        return mb_substr($message, 0, 200);
    }

    private static function assertSafeBaseUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if (! in_array($scheme, ['http', 'https'], true) || ! $host) {
            throw new RuntimeException('WAHA_BASE_URL no es una URL http(s) válida.');
        }

        if ($scheme === 'https' || ! app()->environment('production')) {
            return;
        }

        $isPrivateIp = filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;

        if (! $isPrivateIp) {
            throw new RuntimeException('WAHA_BASE_URL: en producción WAHA solo se alcanza por HTTPS o por una IP privada (nunca http hacia un host público).');
        }
    }
}
