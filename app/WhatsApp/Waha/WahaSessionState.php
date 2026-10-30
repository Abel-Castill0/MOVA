<?php

namespace App\WhatsApp\Waha;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Último estado conocido de la sesión de WAHA (lo alimenta el webhook session.status y las consultas de salud) y
 * pausas por restricciones de WhatsApp (reachoutTimelock / messageCapping). Vive en la caché compartida; es solo una
 * ayuda para no enviar a ciegas: la fuente de verdad sigue siendo GET /api/sessions/{session}.
 */
final class WahaSessionState
{
    private const STATE_KEY = 'waha:session_state';

    private const PAUSE_KEY = 'waha:paused_until';

    /** Estado más reciente si es de hace menos de $maxAgeSeconds; si no, null (desconocido). */
    public static function status(int $maxAgeSeconds = 600): ?string
    {
        $state = Cache::get(self::STATE_KEY);

        if (! is_array($state) || ($state['at'] ?? 0) < now()->subSeconds($maxAgeSeconds)->getTimestamp()) {
            return null;
        }

        return $state['status'] ?? null;
    }

    public static function record(?string $status): void
    {
        if ($status === null || $status === '') {
            return;
        }

        Cache::put(self::STATE_KEY, ['status' => $status, 'at' => now()->getTimestamp()], now()->addDay());
    }

    public static function pauseUntil(Carbon $until): void
    {
        Cache::put(self::PAUSE_KEY, $until->getTimestamp(), $until);
    }

    public static function pausedUntil(): ?Carbon
    {
        $ts = Cache::get(self::PAUSE_KEY);

        return is_numeric($ts) && (int) $ts > now()->getTimestamp() ? Carbon::createFromTimestamp((int) $ts) : null;
    }
}
