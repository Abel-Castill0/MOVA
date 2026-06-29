<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TeacherReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'lesson_id', 'teacher_profile_id', 'parent_id', 'student_id',
        'rating', 'comment', 'is_visible',
        'moderated_at', 'moderated_by', 'moderation_reason',
    ];

    protected $casts = [
        'is_visible'   => 'boolean',
        'moderated_at' => 'datetime',
        'rating'       => 'integer',
    ];

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    public function teacherProfile()
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    public function parent()
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function moderatedBy()
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function scopeVisible($query)
    {
        return $query->where('is_visible', true);
    }
}
