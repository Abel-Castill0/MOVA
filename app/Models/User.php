<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'parental_control',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'parental_control' => 'boolean',
    ];

    public function students()
    {
        return $this->hasMany(Student::class, 'parent_user_id');
    }

    public function teacherProfile()
    {
        return $this->hasOne(TeacherProfile::class);
    }

    public function routeNotificationForWhatsApp(): ?string
    {
        $phone = $this->phone;

        if (!$phone) {
            return null;
        }

        // Strip spaces and dashes
        $phone = preg_replace('/[\s\-]/', '', $phone);

        // Already E.164 with country code
        if (str_starts_with($phone, '+')) {
            return $phone;
        }

        // Peruvian mobile numbers: 9 digits starting with 9
        if (preg_match('/^9\d{8}$/', $phone)) {
            return '+51' . $phone;
        }

        // 11-digit format starting with 51 (without +)
        if (preg_match('/^51\d{9}$/', $phone)) {
            return '+' . $phone;
        }

        return null;
    }
}
