<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ClassRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id', 'subject_id', 'class_offer_id',
        'help_needed', 'preferred_times', 'status',
        'request_reminder_sent_at', 'student_diagnostic_id',
    ];

    protected $casts = [
        'preferred_times'           => 'array',
        'request_reminder_sent_at'  => 'datetime',
    ];

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
