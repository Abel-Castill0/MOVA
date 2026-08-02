<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Lesson extends Model
{
    use HasFactory;

    protected $table = 'classes';

    protected $fillable = [
        'teacher_profile_id', 'student_id', 'class_request_id', 'class_offer_id',
        'start_time', 'duration_minutes', 'price_frozen_pen',
        'jitsi_room', 'jitsi_password', 'status', 'reminder_sent',
        'reminder_24h_sent_at', 'reminder_2h_sent_at', 'report_reminder_sent_at',
        'cancelled_at', 'cancelled_by', 'cancel_reason',
        'original_start_time', 'rescheduled_at', 'rescheduled_by', 'reschedule_reason',
    ];

    protected $casts = [
        'start_time'              => 'datetime',
        'original_start_time'     => 'datetime',
        'reminder_sent'           => 'boolean',
        'reminder_24h_sent_at'    => 'datetime',
        'reminder_2h_sent_at'     => 'datetime',
        'report_reminder_sent_at' => 'datetime',
        'cancelled_at'            => 'datetime',
        'rescheduled_at'          => 'datetime',
    ];

    // La sala de Jitsi funciona como un token de acceso: quien la conoce puede
    // unirse (y, si llega primero, fijar o saltarse el password). Nunca deben
    // salir en un listado — solo LessonController::join() los expone, tras
    // pasar por LessonPolicy::view() y validar el estado de la clase.
    protected $hidden = ['jitsi_room', 'jitsi_password'];

    protected $appends = ['has_jitsi_room', 'end_time', 'credit_cost'];

    public function getHasJitsiRoomAttribute(): bool
    {
        return $this->jitsi_room !== null;
    }

    // Cobro por hora (o fracción): 1 crédito por cada hora iniciada, mínimo 1.
    // Ej: 30 min = 1 crédito, 60 min = 1 crédito, 90 min = 2 créditos.
    public static function creditCostForMinutes(int $durationMinutes): int
    {
        $hours = (int) ceil($durationMinutes / config('credits.credit_minutes', 60));

        return max(1, $hours) * (int) config('credits.cost_per_hour', 1);
    }

    public function getCreditCostAttribute(): int
    {
        return static::creditCostForMinutes($this->duration_minutes);
    }

    // Créditos realmente reservados/consumidos según el ledger, en vez de
    // recalcular desde duration_minutes — así un refund/consumption siempre
    // devuelve exactamente lo que se reservó, incluso si la clase fue
    // reprogramada con otra duración después de aceptarse.
    public function reservedCreditAmount(): int
    {
        $reserved = CreditTransaction::where('lesson_id', $this->id)
            ->where('type', 'reservation')
            ->value('amount');

        return (int) ($reserved ?? $this->credit_cost);
    }

    public function teacherProfile()
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function classRequest()
    {
        return $this->belongsTo(ClassRequest::class);
    }

    public function classOffer()
    {
        return $this->belongsTo(ClassOffer::class);
    }

    public function lessonReport()
    {
        return $this->hasOne(LessonReport::class);
    }

    public function teacherReview()
    {
        return $this->hasOne(TeacherReview::class);
    }

    public function getEndTimeAttribute()
    {
        return $this->start_time->copy()->addMinutes($this->duration_minutes);
    }
}
