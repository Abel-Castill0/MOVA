<?php

namespace App\Realtime;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Azure SignalR Service en modo Serverless: Laravel nunca mantiene WebSockets. Hace dos cosas:
 *   1. entrega a cada navegador, ya autenticado por su sesión, un token SOLO suyo para conectarse al servicio
 *      (clientConnection);
 *   2. publica por la API REST del servicio una señal a un usuario concreto (sendToUser).
 *
 * La señal no transporta datos: solo qué áreas cambiaron (ver RealtimeSignal). El navegador vuelve a pedir esos datos al
 * servidor por HTTP, donde se aplican sesión, rol y alcance como siempre.
 */
class SignalRClient
{
    public static function enabled(): bool
    {
        return config('realtime.driver') === 'signalr'
            && is_string(config('realtime.signalr.endpoint')) && config('realtime.signalr.endpoint') !== ''
            && is_string(config('realtime.signalr.access_key')) && strlen((string) config('realtime.signalr.access_key')) >= 16;
    }

    private function endpoint(): string
    {
        return rtrim((string) config('realtime.signalr.endpoint'), '/');
    }

    private function hub(): string
    {
        return (string) config('realtime.signalr.hub', 'mova');
    }

    private function sign(string $audience, array $claims, int $ttlSeconds): string
    {
        return JWT::encode(
            ['aud' => $audience, 'iat' => time() - 5, 'exp' => time() + $ttlSeconds, ...$claims],
            (string) config('realtime.signalr.access_key'),
            'HS256',
        );
    }

    /**
     * Datos para que el navegador de ESE usuario abra su conexión. El identificador del usuario va firmado dentro del
     * token: el cliente no puede elegir ni cambiar a quién escucha.
     *
     * @return array{url: string, accessToken: string, expiresIn: int}
     */
    public function clientConnection(int $userId): array
    {
        $ttl = (int) config('realtime.signalr.client_token_ttl', 3600);
        $url = $this->endpoint().'/client/?hub='.$this->hub();

        return [
            'url' => $url,
            'accessToken' => $this->sign($url, ['nameid' => (string) $userId, 'asrs.s.uid' => (string) $userId], $ttl),
            'expiresIn' => $ttl,
        ];
    }

    /** @param array<string, mixed> $payload  Nunca datos personales ni de menores. */
    public function sendToUser(int $userId, array $payload): bool
    {
        $url = $this->endpoint().'/api/v1/hubs/'.$this->hub().'/users/'.rawurlencode((string) $userId);

        try {
            $response = Http::withToken($this->sign($url, [], 300))
                ->acceptJson()->connectTimeout(2)->timeout(3)
                ->post($url, ['target' => 'signal', 'arguments' => [$payload]]);
        } catch (\Throwable $e) {
            Log::info('[Realtime] No se pudo publicar la señal.', ['error' => class_basename($e)]);

            return false;
        }

        if (! $response->successful()) {
            Log::info('[Realtime] El servicio rechazó la señal.', ['status' => $response->status()]);
        }

        return $response->successful();
    }
}
