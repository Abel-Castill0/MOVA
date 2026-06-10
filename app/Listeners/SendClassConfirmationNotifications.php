<?php

namespace App\Listeners;

use App\Events\ClassConfirmed;
use App\Notifications\ClassConfirmedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendClassConfirmationNotifications implements ShouldQueue
{
    public function handle(ClassConfirmed $event): void
    {
        $lesson = $event->lesson->load(['teacherProfile.user', 'student.parent']);

        $lesson->teacherProfile->user->notify(new ClassConfirmedNotification($lesson));
        $lesson->student->parent->notify(new ClassConfirmedNotification($lesson));
    }
}
