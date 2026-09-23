<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * P0-J — /healthz = liveness (el proceso responde; sin dependencias).
 *         /readyz  = readiness (puede servir tráfico: DB + tablas de runtime).
 *
 * Nunca devuelven detalles de error ni configuración: solo nombres de check
 * y ok/fail. El detalle va al log.
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
            'database' => fn () => DB::select('select 1'),
            'migrations' => fn () => DB::table('system_heartbeats')->limit(1)->get(),
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
}
