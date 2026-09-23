<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * P0-J — señal POSITIVA de vida para procesos sin HTTP.
 *
 *   scheduler: la escribe un evento everyMinute del propio scheduler.
 *   worker:    la escribe WorkerHeartbeatJob, que el scheduler encola cada
 *              minuto: prueba scheduler -> cola -> worker de punta a punta.
 */
final class Heartbeat
{
    public const SCHEDULER = 'scheduler';

    public const WORKER = 'worker';

    /** Edad a partir de la cual el proceso se considera caído. */
    public const STALE_AFTER_SECONDS = 300;

    public static function beat(string $name): void
    {
        DB::table('system_heartbeats')->upsert(
            [['name' => $name, 'beat_at' => now()]],
            ['name'],
            ['beat_at'],
        );
    }

    /** Segundos desde el último latido, o null si nunca latió. */
    public static function age(string $name): ?int
    {
        $at = DB::table('system_heartbeats')->where('name', $name)->value('beat_at');

        return $at === null ? null : max(0, now()->getTimestamp() - strtotime((string) $at));
    }

    /** healthy | stale | unknown */
    public static function status(string $name): string
    {
        $age = self::age($name);

        return match (true) {
            $age === null => 'unknown',
            $age > self::STALE_AFTER_SECONDS => 'stale',
            default => 'healthy',
        };
    }
}
