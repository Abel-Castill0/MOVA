<?php

namespace App\Services;

use App\Models\TeacherAvailabilitySlot;
use App\Models\TeacherProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Única vía de escritura de la disponibilidad semanal del profesor.
 *
 * Las franjas son horas de pared de Lima (America/Lima; Perú no usa horario de
 * verano). Son informativas: orientan recomendaciones y no autorizan ni
 * bloquean agendas.
 */
class TeacherAvailabilityService
{
    /** Duración mínima de una franja, en minutos. */
    public const MIN_SLOT_MINUTES = 30;

    /**
     * Normaliza y valida las franjas recibidas. Lanza ValidationException con
     * claves `slots.N.campo` para que el formulario muestre cada error donde
     * ocurrió.
     *
     * @param  array<int, array{day_of_week:int|string, start_time:string, end_time:string}>  $slots
     * @return array<int, array{day_of_week:int, start_time:string, end_time:string}>
     */
    public function normalize(array $slots): array
    {
        $errors = [];
        $clean = [];

        foreach (array_values($slots) as $i => $slot) {
            $start = $this->minutes($slot['start_time'] ?? null);
            $end = $this->minutes($slot['end_time'] ?? null);

            if ($end <= $start) {
                $errors["slots.$i.end_time"] = 'La hora de fin debe ser posterior a la de inicio (misma jornada).';
                continue;
            }

            if ($end - $start < self::MIN_SLOT_MINUTES) {
                $errors["slots.$i.end_time"] = 'Cada franja debe durar al menos '.self::MIN_SLOT_MINUTES.' minutos.';
                continue;
            }

            $clean[$i] = ['day_of_week' => (int) $slot['day_of_week'], 'start' => $start, 'end' => $end];
        }

        // Solapes por día (franjas que se tocan en el borde son válidas).
        $byDay = [];
        foreach ($clean as $i => $slot) {
            $byDay[$slot['day_of_week']][] = ['i' => $i] + $slot;
        }
        foreach ($byDay as $daySlots) {
            usort($daySlots, fn ($a, $b) => $a['start'] <=> $b['start']);
            for ($k = 1; $k < count($daySlots); $k++) {
                if ($daySlots[$k]['start'] < $daySlots[$k - 1]['end']) {
                    $errors["slots.{$daySlots[$k]['i']}.start_time"] = 'Esta franja se solapa con otra del mismo día.';
                }
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return collect($clean)
            ->sortBy(fn ($s) => [$s['day_of_week'], $s['start']])
            ->map(fn ($s) => [
                'day_of_week' => $s['day_of_week'],
                'start_time' => $this->format($s['start']),
                'end_time' => $this->format($s['end']),
            ])
            ->values()
            ->all();
    }

    /**
     * Reemplaza TODAS las franjas del profesor de forma atómica. El lock sobre
     * el perfil serializa dos guardados simultáneos (dos pestañas): el último
     * en obtener el lock gana, nunca queda una mezcla de ambos.
     */
    public function replace(TeacherProfile $profile, array $slots): void
    {
        $normalized = $this->normalize($slots);

        DB::transaction(function () use ($profile, $normalized) {
            TeacherProfile::whereKey($profile->getKey())->lockForUpdate()->firstOrFail();

            TeacherAvailabilitySlot::where('teacher_profile_id', $profile->getKey())->delete();

            foreach ($normalized as $slot) {
                TeacherAvailabilitySlot::create($slot + ['teacher_profile_id' => $profile->getKey()]);
            }
        });
    }

    private function minutes(?string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', (string) $hhmm) + [0, 0]);

        return $h * 60 + $m;
    }

    private function format(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
