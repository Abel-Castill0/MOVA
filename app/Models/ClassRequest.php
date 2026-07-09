<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ClassRequest extends Model
{
    use HasFactory;

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

    public function lesson()
    {
        return $this->hasOne(Lesson::class);
    }
}
