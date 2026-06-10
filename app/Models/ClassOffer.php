<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ClassOffer extends Model
{
    use HasFactory;

    protected $fillable = [
        'teacher_profile_id', 'subject_id', 'title',
        'description', 'availability_schedule', 'specific_rate', 'is_active',
    ];

    protected $casts = [
        'availability_schedule' => 'array',
        'is_active' => 'boolean',
        'specific_rate' => 'decimal:2',
    ];

    public function teacherProfile()
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function classRequests()
    {
        return $this->hasMany(ClassRequest::class);
    }

    public function lessons()
    {
        return $this->hasMany(Lesson::class);
    }
}
