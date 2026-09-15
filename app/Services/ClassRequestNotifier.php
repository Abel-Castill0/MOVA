<?php

namespace App\Services;

use App\Models\ClassRequest;
use App\Notifications\NewClassRequestNotification;

class ClassRequestNotifier
{
    /**
     * Único punto que avisa a los profesores elegibles de que una solicitud
     * está disponible — al crearse directamente `open`, o al pasar de
     * `pending_parent_approval` a `open` tras la aprobación del padre.
     * Usado por SendClassRequestNotifications y ClassRequestController::approve()
     * para que ambos caminos no puedan divergir en cómo/cuándo avisan.
     */
    public function notifyEligibleTeachers(ClassRequest $classRequest): void
    {
        $classRequest->loadMissing(['student', 'subject', 'classOffer.teacherProfile.user', 'teacherProfile.user']);

        $classRequest->eligibleTeacherUsers()
            ->each(fn ($user) => $user->notify(new NewClassRequestNotification($classRequest)));
    }
}
