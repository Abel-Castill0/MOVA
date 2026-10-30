<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * Misma ValidationException de siempre (mismo mensaje, misma clave `accept`, mismo 422/redirección), solo tipada para que
 * LessonSchedulingService pueda avisar al profesor DESPUÉS de que la transacción se haya revertido por completo.
 */
class InsufficientCreditsException extends ValidationException
{
    public ?int $teacherProfileId = null;

    public float $needed = 0.0;

    public float $available = 0.0;

    public function forTeacher(int $teacherProfileId, float|int|string $needed, float|int|string $available): static
    {
        $this->teacherProfileId = $teacherProfileId;
        $this->needed = (float) $needed;
        $this->available = (float) $available;

        return $this;
    }
}
