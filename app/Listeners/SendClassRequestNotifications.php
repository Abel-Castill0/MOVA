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
        $request = $event->classRequest->load(['student.parent', 'subject', 'classOffer.teacherProfile.user']);

        if ($request->status === 'pending_parent_approval') {
            $request->student->parent->notify(new ParentApprovalRequestNotification($request));
            return;
        }

        if ($request->class_offer_id && $request->classOffer) {
            $request->classOffer->teacherProfile->user->notify(new NewClassRequestNotification($request));
        } else {
            TeacherProfile::whereHas('subjects', fn($q) => $q->where('subjects.id', $request->subject_id))
                ->where('is_verified', true)
                ->with('user')
                ->get()
                ->each(fn($tp) => $tp->user->notify(new NewClassRequestNotification($request)));
        }
    }
}
