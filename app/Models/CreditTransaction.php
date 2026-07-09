<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CreditTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'teacher_profile_id',
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
}
