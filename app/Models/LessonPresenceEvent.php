<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Evidencia de presencia recibida de JaaS. Solo evidencia: ninguna regla de
 * MOVA (asistencia, ausencia, créditos, liquidación) lee esta tabla todavía.
 */
class LessonPresenceEvent extends Model
{
    public const JOINED = 'participant_joined';

    public const LEFT = 'participant_left';

    protected $fillable = [
        'lesson_id', 'user_id', 'event_type', 'is_moderator', 'disconnect_reason',
        'session_id', 'participant_ref', 'external_event_id', 'occurred_at',
    ];

    protected $casts = [
        'is_moderator' => 'boolean',
        'occurred_at' => 'datetime',
    ];

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
