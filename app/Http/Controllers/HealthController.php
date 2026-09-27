<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Semántica de las sondas (P1-03):
 *
 *   /healthz  LIVENESS del proceso web. Sin dependencias: si falla, reiniciar
 *             el contenedor.
 *   /readyz   READINESS de ESTA INSTANCIA WEB: ¿puede atender requests ahora?
 *             Solo dependencias síncronas del web: DB, esquema al día (ninguna
 *             migración del código pendiente) y el cache store que usan el
 *             rate limiting y el MFA. Sin proveedores externos.
 *
 * /readyz NO significa "todo MOVA está sano": worker y scheduler no forman
 * parte de ella a propósito (su caída no debe sacar al web del balanceador).
 * Su salud va por latidos -> mova:health-check (WORKER/SCHEDULER_HEARTBEAT_*,
 * alertas a administradores) y el Centro de Operaciones.
 *
 * Nunca devuelven detalles de error ni configuración: solo nombre de check y
 * ok/fail. El detalle va al log.
 */
class HealthController extends Controller
{
    public function live()
    {
        return response('OK', 200)->header('Cache-Control', 'no-store');
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'database'   => fn () => DB::select('select 1'),
            'migrations' => fn () => $this->assertNoPendingMigrations(),
            'cache'      => fn () => $this->assertCacheRoundTrip(),
        ];

        $results = [];
        foreach ($checks as $name => $check) {
            try {
                $check();
                $results[$name] = 'ok';
            } catch (Throwable $e) {
                Log::error('[readyz] check failed', ['check' => $name, 'error' => $e->getMessage()]);
                $results[$name] = 'fail';
            }
        }

        $ready = ! in_array('fail', $results, true);

        return response()
            ->json(['status' => $ready ? 'ready' : 'not_ready', 'checks' => $results], $ready ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }

    /** Código nuevo sobre esquema viejo = 500s: la instancia no está lista hasta que corran las migraciones. */
    private function assertNoPendingMigrations(): void
    {
        $files = collect(glob(database_path('migrations/*.php')) ?: [])
            ->map(fn ($path) => pathinfo($path, PATHINFO_FILENAME));

        $applied = DB::table('migrations')->pluck('migration')->flip();
        $pending = $files->reject(fn ($name) => $applied->has($name));

        if ($pending->isNotEmpty()) {
            throw new RuntimeException('Migraciones pendientes: '.$pending->count());
        }
    }

    /** El store por defecto sostiene throttle y MFA: debe poder escribir y leer. */
    private function assertCacheRoundTrip(): void
    {
        $key = 'readyz:'.Str::random(12);
        Cache::put($key, 1, 10);
        $ok = Cache::get($key) === 1;
        Cache::forget($key);

        if (! $ok) {
            throw new RuntimeException('Cache sin round-trip.');
        }
    }
}
