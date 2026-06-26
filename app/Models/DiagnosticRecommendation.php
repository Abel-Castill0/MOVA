<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DiagnosticRecommendation extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_diagnostic_id', 'class_offer_id', 'teacher_profile_id',
        'rank', 'score', 'reasons',
    ];

    protected $casts = [
        'reasons' => 'array',
    ];

    public function diagnostic()
    {
        return $this->belongsTo(StudentDiagnostic::class, 'student_diagnostic_id');
    }

    public function classOffer()
    {
        return $this->belongsTo(ClassOffer::class);
    }

    public function teacherProfile()
    {
        return $this->belongsTo(TeacherProfile::class);
    }
}
