<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StudentDiagnostic extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_user_id', 'student_id', 'subject_id', 'level',
        'difficulty_text', 'school_feedback',
        'goal', 'urgency', 'status',
        'ai_keywords', 'ai_detected_level', 'ai_summary', 'ai_suggested_goal',
        'ai_risk_flags', 'ai_confidence', 'ai_used_fallback', 'ai_enriched_at',
    ];

    protected $casts = [
        'ai_keywords'   => 'array',
        'ai_risk_flags' => 'array',
        'ai_enriched_at' => 'datetime',
    ];

    public function parent()
    {
        return $this->belongsTo(User::class, 'parent_user_id');
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

    public function recommendations()
    {
        return $this->hasMany(DiagnosticRecommendation::class)->orderBy('rank');
    }

    public function classRequest()
    {
        return $this->hasOne(ClassRequest::class);
    }
}
