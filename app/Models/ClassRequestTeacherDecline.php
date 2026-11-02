<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassRequestTeacherDecline extends Model
{
    public $timestamps = false;

    protected $fillable = ['class_request_id', 'teacher_profile_id', 'reason', 'declined_at'];

    protected $casts = ['declined_at' => 'datetime'];
}
