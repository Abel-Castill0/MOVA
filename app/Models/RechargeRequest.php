<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RechargeRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'teacher_profile_id',
        'package_name',
        'credits',
        'amount_pen',
        'operation_number',
        'status',
    ];

    protected $casts = [
        'credits' => 'integer',
        'amount_pen' => 'decimal:2',
    ];

    public function teacherProfile()
    {
        return $this->belongsTo(TeacherProfile::class);
    }
}
