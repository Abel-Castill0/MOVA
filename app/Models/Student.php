<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Student extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_user_id', 'first_name', 'last_name',
        'birth_date', 'grade_level', 'school',
    ];

    protected $appends = ['full_name'];

    public function parent()
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }

    public function classRequests()
    {
        return $this->hasMany(ClassRequest::class);
    }

    public function classes()
    {
        return $this->hasMany(Lesson::class);
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }
}
