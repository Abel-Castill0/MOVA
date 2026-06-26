<?php

namespace App\Listeners;

use App\Notifications\WelcomeEmailNotification;
use Illuminate\Auth\Events\Verified;

class SendWelcomeAfterVerification
{
    public function handle(Verified $event): void
    {
        $user = $event->user;

        // Only send if the user already received the in-app welcome at registration
        if (!$user->welcome_notification_sent_at) {
            return;
        }

        $user->notify(new WelcomeEmailNotification());
    }
}
