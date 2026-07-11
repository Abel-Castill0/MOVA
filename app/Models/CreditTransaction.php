<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CreditTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'teacher_profile_id',
        'idempotency_key',
        'lesson_id',
        'recharge_request_id',
        'type',
        'amount',
        'description',
    ];

    protected $casts = [
        'amount' => 'integer',
    ];

    public function teacherProfile()
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    public function rechargeRequest()
    {
        return $this->belongsTo(RechargeRequest::class);
    }
}
