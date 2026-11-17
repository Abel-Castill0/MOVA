<?php

namespace App\Realtime;

use Firebase\JWT\JWT;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
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

    private const BREAKER_KEY = 'realtime:signalr:down';
    private const BREAKER_SECONDS = 60;

    /** @param array<string, mixed> $payload  Nunca datos personales ni de menores. */
    public function sendToUser(int $userId, array $payload): bool
    {
        return $this->sendToUsers([$userId], $payload);
    }

    /**
     * Publica la misma señal a varias personas EN PARALELO (una sola espera, no una por destinatario) con tiempos
     * cortos. Si el servicio falla o no responde, se abre un corte de 60 s: durante ese tiempo no se intenta nada y las
     * operaciones de MOVA no pagan la espera (la interfaz sigue con su refresco normal).
     *
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $payload  Nunca datos personales ni de menores.
     */
    public function sendToUsers(array $userIds, array $payload): bool
    {
        if ($userIds === [] || Cache::has(self::BREAKER_KEY)) {
            return false;
        }

        $base = $this->endpoint().'/api/v1/hubs/'.$this->hub().'/users/';
        $body = ['target' => 'signal', 'arguments' => [$payload]];

        try {
            $responses = Http::pool(function (Pool $pool) use ($userIds, $base, $body) {
                foreach ($userIds as $id) {
                    $url = $base.rawurlencode((string) $id);
                    $pool->as((string) $id)->withToken($this->sign($url, [], 300))->acceptJson()
                        ->connectTimeout(1)->timeout(2)->post($url, $body);
                }
            });
        } catch (\Throwable $e) {
            return $this->trip(class_basename($e));
        }

        $failed = collect($responses)->filter(fn ($r) => ! ($r instanceof \Illuminate\Http\Client\Response) || ! $r->successful());
        if ($failed->isNotEmpty()) {
            $first = $failed->first();

            return $this->trip($first instanceof \Illuminate\Http\Client\Response ? 'HTTP '.$first->status() : class_basename($first));
        }

        return true;
    }

    private function trip(string $reason): bool
    {
        Cache::put(self::BREAKER_KEY, 1, self::BREAKER_SECONDS);
        Log::info('[Realtime] Servicio de señales no disponible; se pausa 60 s.', ['reason' => $reason]);

        return false;
    }
}
