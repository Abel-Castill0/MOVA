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

    public function student()
    {
        return $this->belongsTo(Student::class);
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
