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
        // Campos de contraoferta — asignados solo desde CounterofferController
        // y CounterofferWebhookController, nunca desde requests de usuario.
        'counteroffer_time', 'counteroffer_teacher_profile_id', 'meeting_link',
    ];

    protected $casts = [
        'preferred_times'            => 'array',
        'is_mentorship'              => 'boolean',
        'request_reminder_sent_at'   => 'datetime',
        'teacher_rejected_at'        => 'datetime',
        'counteroffer_time'          => 'datetime',
    ];

    public function isTeacherRejected(): bool
    {
        return $this->status === 'teacher_rejected';
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

    /**
     * Usuarios-profesor que deben enterarse de esta solicitud. Tres caminos
     * excluyentes, en orden de especificidad:
     *
     *   1. teacher_profile_id  -> código de referido (Opción A): EXCLUSIVA de
     *      ese profesor, nunca se difunde por materia.
     *   2. class_offer_id      -> el profesor dueño de la oferta.
     *   3. ninguno (abierta)   -> todos los profesores VERIFICADOS de la materia.
     *
     * F-10: esta resolución vivía solo dentro de SendClassRequestNotifications.
     * SendClassReminders reimplementaba únicamente el caso 2, así que las
     * solicitudes de los casos 1 y 3 —el flujo mayoritario— se marcaban como
     * avisadas sin avisar a nadie, y quedaban excluidas para siempre del
     * barrido. Ahora ambos caminos comparten este método y no pueden divergir.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function eligibleTeacherUsers(): \Illuminate\Support\Collection
    {
        if ($this->teacher_profile_id) {
            $user = $this->teacherProfile?->user;

            return $user ? collect([$user]) : collect();
        }

        if ($this->class_offer_id) {
            $user = $this->classOffer?->teacherProfile?->user;

            return $user ? collect([$user]) : collect();
        }

        return TeacherProfile::whereHas('subjects', fn ($q) => $q->where('subjects.id', $this->subject_id))
            ->where('is_verified', true)
            ->with('user')
            ->get()
            ->map(fn (TeacherProfile $profile) => $profile->user)
            ->filter()
            ->values();
    }

    public function lesson()
    {
        return $this->hasOne(Lesson::class);
    }

    /**
     * El profesor que hizo la contraoferta de horario. Distinto de
     * teacherProfile() (el profesor al que va dirigida la solicitud por
     * código de referido) — un profesor sin código de referido también
     * puede hacer una contraoferta sobre una solicitud abierta.
     */
    public function counterofferTeacherProfile()
    {
        return $this->belongsTo(TeacherProfile::class, 'counteroffer_teacher_profile_id');
    }
}
