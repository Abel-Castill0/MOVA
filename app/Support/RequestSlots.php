<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Día + franja horaria de una solicitud de clase (Mañana / Tarde / Noche), siempre en hora de Lima.
 *
 * La fecha preferida es un día calendario de Lima («2026-11-20») y la franja una clave de config('class_requests.slots').
 * Nunca se convierte a una clase: solo se traduce a una ventana de instantes UTC para validar y para decidir si una hora
 * propuesta cae dentro de lo que pidió la familia. La conversión Lima→UTC ocurre aquí, una sola vez.
 */
class RequestSlots
{
    /** @return array<string, array{label: string, start: int, end: int}> */
    public static function all(): array
    {
        return config('class_requests.slots', []);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function exists(?string $slot): bool
    {
        return $slot !== null && array_key_exists($slot, self::all());
    }

    public static function label(?string $slot): ?string
    {
        return self::all()[$slot]['label'] ?? null;
    }

    /** Día calendario de hoy en Lima («2026-11-19»). */
    public static function today(?CarbonInterface $now = null): string
    {
        return ($now ? LimaClock::at($now) : Carbon::now(LimaClock::TIMEZONE))->format('Y-m-d');
    }

    /** ¿«Y-m-d» es una fecha de calendario real? */
    public static function isValidDate(?string $date): bool
    {
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $date));

        return checkdate($m, $d, $y);
    }

    /**
     * Ventana [inicio, fin) de la franja ese día, como instantes UTC. null si la fecha o la franja no existen.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public static function window(?string $date, ?string $slot): ?array
    {
        if (! self::isValidDate($date) || ! self::exists($slot)) {
            return null;
        }
        $def = self::all()[$slot];
        $day = Carbon::createFromFormat('Y-m-d', $date, LimaClock::TIMEZONE)->startOfDay();

        return [
            $day->copy()->addHours($def['start'])->utc(),
            $day->copy()->addHours($def['end'])->utc(),
        ];
    }

    /** ¿La franja de ese día ya terminó? (hoy, tarde por la noche: «Mañana» ya no se ofrece). */
    public static function hasEnded(?string $date, ?string $slot, ?CarbonInterface $now = null): bool
    {
        $window = self::window($date, $slot);

        return $window === null || ($now ?? now())->greaterThanOrEqualTo($window[1]);
    }

    /** ¿Este instante cae dentro del día + franja pedidos? */
    public static function fits(?string $date, ?string $slot, CarbonInterface $instant): bool
    {
        $window = self::window($date, $slot);

        return $window !== null && $instant->greaterThanOrEqualTo($window[0]) && $instant->lessThan($window[1]);
    }

    /** «viernes 20 de noviembre» (día de Lima, en español). */
    public static function dayLabel(?string $date): ?string
    {
        if (! self::isValidDate($date)) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', $date, LimaClock::TIMEZONE)->locale('es')->translatedFormat('l j \d\e F');
    }

    /** «viernes 20 de noviembre, por la tarde» — como lo lee el profesor. */
    public static function describe(?string $date, ?string $slot): ?string
    {
        $day = self::dayLabel($date);
        $label = self::label($slot);
        if ($day === null || $label === null) {
            return null;
        }

        return $day.', por la '.mb_strtolower(match ($slot) {'morning' => 'mañana', 'afternoon' => 'tarde', 'evening' => 'noche', default => $label});
    }
}
