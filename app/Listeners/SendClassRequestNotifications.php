<?php

namespace App\Listeners;

use App\Events\ClassRequestCreated;
use App\Models\TeacherProfile;
use App\Notifications\NewClassRequestNotification;
use App\Notifications\ParentApprovalRequestNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendClassRequestNotifications implements ShouldQueue
{
    public function handle(ClassRequestCreated $event): void
    {
        $request = $event->classRequest->load(['student.parent', 'subject', 'classOffer.teacherProfile.user', 'teacherProfile.user']);

        if ($request->status === 'pending_parent_approval') {
            $request->student->parent->notify(new ParentApprovalRequestNotification($request));
            return;
        }

        // Código de referido (Opción A): la solicitud ya está vinculada a UN
        // profesor específico — solo a él, nunca a todos los que enseñan la
        // materia (ese era el comportamiento de las solicitudes abiertas).
        //
        // F-10: la resolución de destinatarios vive ahora en
        // ClassRequest::eligibleTeacherUsers(), compartida con el recordatorio
        // de solicitudes sin responder (SendClassReminders). Estaba duplicada
        // a medias entre ambos y el recordatorio solo cubría el caso de la
        // oferta, perdiendo silenciosamente los otros dos.
        $request->eligibleTeacherUsers()
            ->each(fn ($user) => $user->notify(new NewClassRequestNotification($request)));
    }
}
