<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\TeacherAvailabilitySlot;
use App\Services\TeacherAvailabilityService;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    /**
     * El profesor edita SOLO su propia disponibilidad: el perfil sale de la
     * sesión, nunca de un id enviado por el cliente.
     */
    public function update(Request $request, TeacherAvailabilityService $service)
    {
        $profile = $request->user()->teacherProfile;
        abort_unless($profile, 403, 'No tienes perfil de profesor.');

        $data = $request->validate([
            'slots' => ['present', 'array', 'max:'.TeacherAvailabilitySlot::MAX_SLOTS],
            'slots.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'slots.*.start_time' => ['required', 'date_format:H:i'],
            'slots.*.end_time' => ['required', 'date_format:H:i'],
        ], [
            'slots.max' => 'Puedes declarar hasta '.TeacherAvailabilitySlot::MAX_SLOTS.' franjas.',
            'slots.*.day_of_week.between' => 'Día inválido.',
            'slots.*.start_time.date_format' => 'Usa el formato HH:MM.',
            'slots.*.end_time.date_format' => 'Usa el formato HH:MM.',
        ]);

        $service->replace($profile, $data['slots']);

        return redirect()->route('teacher.profile')->with('success', 'Disponibilidad actualizada.');
    }
}
