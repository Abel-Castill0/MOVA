<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * BUG-5 (docs/MOVA_AUDIT_PHASE0.md, sección Q) — `config('app.timezone')` es
 * `UTC` (correcto: todos los timestamps de BD viven en UTC, sin ambigüedad
 * de zona horaria en el almacenamiento). Pero 3 consultas puntuales usaban
 * `today()`/`whereDate(..., today())` para decidir qué cuenta como "hoy" en
 * paneles y límites diarios operados por personas en Perú (UTC-5, sin
 * horario de verano) — el corte real ocurría a las 7pm hora de Lima, no a
 * medianoche.
 *
 * Se resuelve aquí, puntualmente, en vez de cambiar `config('app.timezone')`
 * globalmente (eso afectaría cómo se interpretan TODOS los timestamps del
 * sistema, un cambio mucho más invasivo y fuera del alcance de este fix).
 */
class LimaClock
{
    public const TIMEZONE = 'America/Lima';

    /**
     * Rango [inicio, fin) del día calendario actual en Lima, expresado en UTC
     * — listo para usarse directamente contra columnas de BD, que siguen en
     * UTC. Usar con `whereBetween($column, LimaClock::todayRangeUtc())` o
     * `where($column, '>=', $start)->where($column, '<', $end)`.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function todayRangeUtc(): array
    {
        $startOfDayLima = Carbon::now(self::TIMEZONE)->startOfDay();

        return [
            $startOfDayLima->clone()->setTimezone('UTC'),
            $startOfDayLima->clone()->addDay()->setTimezone('UTC'),
        ];
    }
}
