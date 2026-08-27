<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TeacherReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'lesson_id', 'teacher_profile_id', 'parent_id', 'student_id',
        'rating', 'comment', 'is_visible',
        'moderated_at', 'moderated_by', 'moderation_reason',
    ];

    protected $casts = [
        'is_visible'   => 'boolean',
        'moderated_at' => 'datetime',
        'rating'       => 'integer',
    ];

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    public function teacherProfile()
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    public function parent()
    {
        return $this->belongsTo(User::class, 'parent_id');
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
    public function student()
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function moderatedBy()
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function scopeVisible($query)
    {
        return $query->where('is_visible', true);
    }
}
