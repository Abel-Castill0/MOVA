<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ClassRequest extends Model
{
    use HasFactory;

    // teacher_profile_id y teacher_referral_code NO están aquí a propósito:
    // solo ClassRequestController::store() debe fijarlos, tras validar el
    // código contra la BD — dejarlos mass-assignable permitiría que un
    // request manipulado se autoasignara a cualquier profesor sin pasar por
    // esa validación (ver ClassRequestPolicy::accept(), que confía en este
    // campo para dar acceso exclusivo).
    protected $fillable = [
        'student_id', 'subject_id', 'class_offer_id',
        'is_mentorship', 'help_needed', 'preferred_times', 'status',
        'request_reminder_sent_at', 'student_diagnostic_id',
        'teacher_rejected_at', 'teacher_rejection_reason',
    ];

    protected $casts = [
        'preferred_times'            => 'array',
        'is_mentorship'              => 'boolean',
        'request_reminder_sent_at'   => 'datetime',
        'teacher_rejected_at'        => 'datetime',
    ];

    public function isTeacherRejected(): bool
    {
        return $this->status === 'teacher_rejected';
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function classOffer()
    {
        return $this->belongsTo(ClassOffer::class);
    }

    // Vínculo directo por código de referido (Opción A) — NULL si la
    // solicitud está abierta a quien matchee por oferta/materia.
    public function teacherProfile()
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    /**
     * Solicitudes que un profesor puede ver/gestionar: vinculadas
     * directamente a él por código de referido, O sin vínculo directo y que
     * matcheen por oferta/materia (el comportamiento de siempre). Antes esta
     * condición estaba duplicada palabra por palabra entre 'open' y
     * 'teacher_rejected' en ClassRequestController::teacherIndex() — la
     * misma deuda que hasScheduleOverlap() dejó duplicado en C-2.
     */
    public function scopeVisibleToTeacher(Builder $query, int $teacherProfileId, $offerIds, $subjectIds): Builder
    {
        return $query->where(function ($q) use ($teacherProfileId, $offerIds, $subjectIds) {
            $q->where('teacher_profile_id', $teacherProfileId)
                ->orWhere(function ($inner) use ($offerIds, $subjectIds) {
                    $inner->whereNull('teacher_profile_id')
                        ->where(function ($q2) use ($offerIds, $subjectIds) {
                            $q2->whereIn('class_offer_id', $offerIds)
                                ->orWhere(function ($q3) use ($subjectIds) {
                                    $q3->whereNull('class_offer_id')->whereIn('subject_id', $subjectIds);
                                });
                        });
                });
        });
    }

    public function lesson()
    {
        return $this->hasOne(Lesson::class);
    }
}
