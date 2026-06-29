<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassEvent extends Model
{
    protected $fillable = [
        'lesson_id', 'class_request_id', 'actor_id',
        'event_type', 'reason', 'metadata',
    ];

    protected $casts = ['metadata' => 'array'];

    public static function log(
        string $eventType,
        int $actorId,
        ?int $lessonId = null,
        ?int $classRequestId = null,
        ?string $reason = null,
        array $metadata = []
    ): void {
        static::create([
            'event_type'       => $eventType,
            'actor_id'         => $actorId,
            'lesson_id'        => $lessonId,
            'class_request_id' => $classRequestId,
            'reason'           => $reason,
            'metadata'         => $metadata ?: null,
        ]);
    }
}
