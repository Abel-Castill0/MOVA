<?php

namespace App\Realtime;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Único punto por el que MOVA avisa en vivo a un navegador de que "algo cambió".
 *
 * Reglas de diseño (seguridad y robustez):
 *  - La señal solo lleva temas (`requests`, `lessons`, `credits`, `notifications`, `dashboard`), un id y la hora. Jamás
 *    nombres, ids de solicitudes o alumnos, importes ni saldos: el navegador pide los datos por HTTP y el servidor decide.
 *  - Se envía DESPUÉS del commit de la transacción en curso (DB::afterCommit): si la transacción se revierte, no sale nada.
 *  - Un fallo del servicio de tiempo real nunca rompe la operación de negocio ni retrasa más de unos segundos.
 *  - Cada señal lleva un id: el navegador descarta repetidas.
 */
class RealtimeSignal
{
    public const TOPICS = ['requests', 'lessons', 'credits', 'notifications', 'dashboard'];

    /**
     * @param  iterable<int|string|null>|Closure  $userIds  ids de usuario, o una función que los resuelve tras el commit
     * @param  list<string>  $topics
     */
    public static function to(iterable|Closure $userIds, array $topics): void
    {
        if (! SignalRClient::enabled()) {
            return;
        }

        $topics = array_values(array_intersect(self::TOPICS, $topics));
        if ($topics === []) {
            return;
        }

        DB::afterCommit(function () use ($userIds, $topics) {
            try {
                $ids = collect($userIds instanceof Closure ? $userIds() : $userIds)
                    ->filter(fn ($id) => $id !== null && $id !== '')
                    ->map(fn ($id) => (int) $id)->filter()->unique()->values();
                if ($ids->isEmpty()) {
                    return;
                }

                app(SignalRClient::class)->sendToUsers($ids->all(), ['id' => (string) Str::uuid(), 'topics' => $topics, 'at' => now()->toIso8601String()]);
            } catch (\Throwable $e) {
                Log::info('[Realtime] Señal omitida.', ['error' => class_basename($e)]);
            }
        });
    }
}
