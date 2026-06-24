<?php

namespace App\Channels;

use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class SafeMailChannel extends MailChannel
{
    public function send($notifiable, Notification $notification): void
    {
        $mailer = config('mail.default');

        // Gmail API path — uses HTTPS, not SMTP; safe on Railway Hobby
        if ($mailer === 'gmail_api') {
            if (empty(config('services.gmail.refresh_token'))) {
                Log::warning('[Mail] GMAIL_REFRESH_TOKEN not configured — skipping email.', [
                    'notification' => class_basename($notification),
                ]);
                return;
            }
            app(\App\Channels\GmailApiMailChannel::class)->send($notifiable, $notification);
            return;
        }

        // No-op transports — skip silently
        if (in_array($mailer, ['array', 'log'], true)) {
            return;
        }

        // Resend configured but key missing
        if ($mailer === 'resend' && empty(config('services.resend.key'))) {
            Log::warning('[Mail] RESEND_API_KEY not configured — skipping email.', [
                'notification' => class_basename($notification),
            ]);
            return;
        }

        // SMTP configured but no credentials — avoids connecting to smtp.mailgun.org default
        if ($mailer === 'smtp' && empty(config('mail.mailers.smtp.username'))) {
            Log::warning('[Mail] SMTP credentials not configured — skipping email.', [
                'notification' => class_basename($notification),
            ]);
            return;
        }

        try {
            parent::send($notifiable, $notification);
        } catch (\Throwable $e) {
            Log::error('[Mail] Notification email failed.', [
                'notification' => class_basename($notification),
                'error'        => $e->getMessage(),
            ]);
            // Intentionally swallowed: database and WhatsApp channels must continue
        }
    }
}
