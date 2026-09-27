<?php

namespace App\Listeners;

use App\Events\ClassRequestCreated;
use App\Notifications\ParentApprovalRequestNotification;
use App\Services\ClassRequestNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendClassRequestNotifications implements ShouldQueue
{
    public function __construct(private ClassRequestNotifier $notifier) {}

    public function handle(ClassRequestCreated $event): void
    {
        $request = $event->classRequest->load(['student.parent', 'subject']);

        if ($request->status === 'pending_parent_approval') {
            $request->student->parent->notify(new ParentApprovalRequestNotification($request));
            return;
        }

        // Creada directamente `open` (sin control parental): los profesores
        // elegibles se avisan aquí mismo. Si en cambio nace
        // `pending_parent_approval`, este aviso llega más tarde, cuando el
        // padre aprueba — ver ClassRequestController::approve(), que reutiliza
        // el mismo ClassRequestNotifier para no divergir en cómo se resuelve
        // ni se notifica al destinatario.
        $this->notifier->notifyEligibleTeachers($request);
    }
}
