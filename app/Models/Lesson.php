<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Lesson extends Model
{
    use HasFactory;

    protected $table = 'classes';

    protected $fillable = [
        'teacher_profile_id', 'student_id', 'class_request_id', 'class_offer_id',
        'start_time', 'duration_minutes', 'zoom_meeting_id', 'zoom_link',
        'zoom_password', 'status', 'reminder_sent',
        'reminder_24h_sent_at', 'reminder_2h_sent_at', 'report_reminder_sent_at',
        'cancelled_at', 'cancelled_by', 'cancel_reason',
        'original_start_time', 'rescheduled_at', 'rescheduled_by', 'reschedule_reason',
    ];

    protected $casts = [
        'start_time'              => 'datetime',
        'original_start_time'     => 'datetime',
        'reminder_sent'           => 'boolean',
        'reminder_24h_sent_at'    => 'datetime',
        'reminder_2h_sent_at'     => 'datetime',
        'report_reminder_sent_at' => 'datetime',
        'cancelled_at'            => 'datetime',
        'rescheduled_at'          => 'datetime',
    ];

    public function teacherProfile()
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function classRequest()
    {
        return $this->belongsTo(ClassRequest::class);
    }

    public function classOffer()
    {
        return $this->belongsTo(ClassOffer::class);
    }

    public function lessonReport()
    {
        return $this->hasOne(LessonReport::class);
    }

    public function teacherReview()
    {
        return $this->hasOne(TeacherReview::class);
    }

    public function getEndTimeAttribute()
    {
        return $this->start_time->addMinutes($this->duration_minutes);
    }
}
