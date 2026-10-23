<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Events\NotificationSending;

class PreventClosedAccountNotifications
{
    public function handle(NotificationSending $event): ?bool
    {
        if ($event->notifiable instanceof User
            && User::whereKey($event->notifiable->id)->whereNotNull('account_closed_at')->exists()
            && ! $event->notification instanceof ResetPassword) {
            return false;
        }
        // A password reset only restores access to the request status, never the dashboard.
        return null;
    }
}
