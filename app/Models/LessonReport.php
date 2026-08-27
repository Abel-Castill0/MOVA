<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonReport extends Model
{
    protected $fillable = [
        'lesson_id',
        'teacher_profile_id',
        'student_id',
        'topic_covered',
        'student_performance',
        'difficulties_detected',
        'homework_assigned',
        'teacher_recommendation',
        'next_step',
        'sent_to_parent_at',
    ];

    protected $casts = [
        'sent_to_parent_at' => 'datetime',
    ];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    /**
     * F-18 (regresión corregida en Fase 4) — `withTrashed()` es OBLIGATORIO.
     *
     * Al añadir SoftDeletes a Student, esta relación empezó a devolver NULL en
     * cuanto el padre daba de baja al alumno. Verificado empíricamente: las
     * clases históricas perdían su alumno, y —más grave— `?->parent?->notify()`
     * dejaba de encontrar destinatario, así que el padre dejaba de recibir
     * avisos de cancelación y devolución SIN ningún error visible.
     *
     * Un registro histórico debe seguir resolviendo su alumno aunque este ya
     * no aparezca en la lista activa del padre. Los datos personales del menor
     * ya se sustituyen al darlo de baja (Student::anonymize()), así que esto no
     * reexpone información.
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }
}
