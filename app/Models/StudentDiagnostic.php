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
