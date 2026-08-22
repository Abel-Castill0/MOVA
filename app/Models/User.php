<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    // phone_verified_normalized NO está aquí a propósito: es la garantía
    // UNIQUE contra teléfono duplicado (hallazgo CRÍTICO, 2026-08-22). Solo
    // PhoneVerificationController::verify() debe escribirla, con asignación
    // directa — igual que credits_settled_at en Lesson.
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'avatar_url',
        'email_verified_at',
        'parental_control',
        'phone_verified_at',
        'phone_verification_code_hash',
        'phone_verification_expires_at',
        'phone_verification_attempts',
        'welcome_notification_sent_at',
        'suspended_at',
        'suspension_reason',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at'            => 'datetime',
        'phone_verified_at'            => 'datetime',
        'password'                     => 'hashed',
        'parental_control'             => 'boolean',
        'phone_verification_attempts'  => 'integer',
        'phone_verification_expires_at'   => 'datetime',
        'welcome_notification_sent_at'    => 'datetime',
        'suspended_at'                    => 'datetime',
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
        return $this->normalizePhone($this->phone);
    }

    public function routeNotificationForMail(): string
    {
        return $this->email;
    }

    public static function normalizePhone(?string $phone): ?string
    {
        if (!$phone) return null;

        $phone = preg_replace('/[\s\-\(\)]/', '', $phone);

        if (str_starts_with($phone, '+')) {
            return strlen($phone) >= 10 ? $phone : null;
        }

        // Peruvian 9-digit mobile (starts with 9)
        if (preg_match('/^9\d{8}$/', $phone)) {
            return '+51' . $phone;
        }

        // 11-digit starting with 51 (without +)
        if (preg_match('/^51\d{9}$/', $phone)) {
            return '+' . $phone;
        }

        return null;
    }
}
