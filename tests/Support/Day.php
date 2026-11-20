<?php

namespace Tests\Support;

use App\Support\RequestSlots;
use Illuminate\Support\Carbon;

/** Día + franja válidos para el POST de una solicitud (mañana en Lima, por la tarde: nunca pasado ni ya terminado). */
class Day
{
    public static function tomorrow(string $slot = 'afternoon'): array
    {
        return [
            'preferred_date' => Carbon::createFromFormat('Y-m-d', RequestSlots::today(), 'America/Lima')->addDay()->format('Y-m-d'),
            'preferred_slot' => $slot,
        ];
    }
}
