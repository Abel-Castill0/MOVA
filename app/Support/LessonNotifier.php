<?php

namespace App\Support;

use App\Models\Lesson;
use Illuminate\Notifications\Notification;

/**
 * Único punto para "notificar a ambas partes de una lección" (profesor y
 * padre). Antes vivía duplicado, palabra por palabra, en
 * SendClassReminders::notifyBoth() y LessonSettlementService::notifyBoth()
 * — hallazgo de la pasada de code-review de la Decisión #3 (Fase 3B §17):
 * si la relación para llegar al profesor/padre de una lección cambia (ej.
 * un futuro modelo de múltiples apoderados), corregirla en una sola copia y
 * olvidar la otra dejaría una de las dos notificando a la persona
 * equivocada o a nadie, en silencio.
 */
class LessonNotifier
{
    /**
     * Clona la notificación para el segundo destinatario: evita compartir
     * una misma instancia entre dos jobs de cola independientes.
     */
    public static function notifyBoth(Lesson $lesson, Notification $notification): void
    {
        if ($lesson->teacherProfile?->user) {
            $lesson->teacherProfile->user->notify($notification);
        }
        if ($lesson->student?->parent) {
            $lesson->student->parent->notify(clone $notification);
        }
    }
}
