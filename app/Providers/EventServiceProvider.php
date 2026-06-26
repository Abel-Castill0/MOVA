<?php

namespace App\Providers;

use App\Events\ClassConfirmed;
use App\Events\ClassRequestCreated;
use App\Listeners\SendClassConfirmationNotifications;
use App\Listeners\SendClassRequestNotifications;
use App\Listeners\SendWelcomeAfterVerification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        ClassConfirmed::class => [
            SendClassConfirmationNotifications::class,
        ],
        ClassRequestCreated::class => [
            SendClassRequestNotifications::class,
        ],
        Verified::class => [
            SendWelcomeAfterVerification::class,
        ],
    ];

    public function boot(): void {}

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
