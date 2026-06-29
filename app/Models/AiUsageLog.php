<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsageLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'student_diagnostic_id',
        'provider',
        'model',
        'status',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'error_type',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function diagnostic()
    {
        return $this->belongsTo(StudentDiagnostic::class, 'student_diagnostic_id');
    }
}
