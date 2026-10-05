<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Franja semanal que el profesor declara como disponible. Informativa: no
 * autoriza ni bloquea agendas (ver la migración de la tabla).
 */
class TeacherAvailabilitySlot extends Model
{
    public const DAY_NAMES = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    public const DAY_LABELS = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

    /** Tope de franjas por profesor: evita payloads abusivos y listas ilegibles. */
    public const MAX_SLOTS = 28;

    protected $fillable = ['teacher_profile_id', 'day_of_week', 'start_time', 'end_time'];

    protected $casts = ['day_of_week' => 'integer'];

    public function teacherProfile()
    {
        return $this->belongsTo(TeacherProfile::class);
    }
}
