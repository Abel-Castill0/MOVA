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

    /**
     * $actorId = NULL significa que el actor es el propio sistema (MOVA), no
     * un usuario. Lo usan los procesos automáticos, que no tienen sesión
     * detrás pero deben auditarse igual que cualquier acción humana.
     */
    public static function log(
        string $eventType,
        ?int $actorId,
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
