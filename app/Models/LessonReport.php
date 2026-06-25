<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonReport extends Model
{
    protected $fillable = [
        'lesson_id',
        'teacher_profile_id',
        'student_id',
        'topic_covered',
        'student_performance',
        'difficulties_detected',
        'homework_assigned',
        'teacher_recommendation',
        'next_step',
        'sent_to_parent_at',
    ];

    protected $casts = [
        'sent_to_parent_at' => 'datetime',
    ];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
