<?php

namespace App\Providers;

use App\Channels\SafeMailChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Replace the default mail channel so email failures never crash a notification job
        $this->app->bind(MailChannel::class, SafeMailChannel::class);
    }

    public function boot(): void
    {
        if (app()->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
