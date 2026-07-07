<?php

namespace App\Channels;

use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class SafeMailChannel extends MailChannel
{
    public function send($notifiable, Notification $notification)
    {
        $mailer = config('mail.default');

        // Gmail API path uses HTTPS, not SMTP; safe on Railway Hobby.
        if ($mailer === 'gmail_api') {
            if (empty(config('services.gmail.refresh_token'))) {
                Log::warning('[Mail] GMAIL_REFRESH_TOKEN not configured; skipping Gmail API.', [
                    'notification' => class_basename($notification),
                ]);
            } elseif (app(GmailApiMailChannel::class)->send($notifiable, $notification)) {
                return;
            }

            if ($this->canUseSmtpFallback()) {
                Log::warning('[Mail] Gmail API failed; falling back to SMTP.', [
                    'notification' => class_basename($notification),
                ]);
                return $this->sendUsingMailer('smtp', $notifiable, $notification);
            }

            return;
        }

        if (in_array($mailer, ['array', 'log'], true)) {
            return;
        }

        if ($mailer === 'resend' && empty(config('services.resend.key'))) {
            Log::warning('[Mail] RESEND_API_KEY not configured; skipping email.', [
                'notification' => class_basename($notification),
            ]);
            return;
        }

        if ($mailer === 'smtp' && empty(config('mail.mailers.smtp.username'))) {
            Log::warning('[Mail] SMTP credentials not configured; skipping email.', [
                'notification' => class_basename($notification),
            ]);
            return;
        }

        try {
            return parent::send($notifiable, $notification);
        } catch (\Throwable $e) {
            Log::error('[Mail] Notification email failed.', [
                'notification' => class_basename($notification),
                'error'        => $e->getMessage(),
            ]);
        }
    }

    private function canUseSmtpFallback(): bool
    {
        return !empty(config('mail.mailers.smtp.host'))
            && !empty(config('mail.mailers.smtp.username'))
            && !empty(config('mail.mailers.smtp.password'));
    }

    private function sendUsingMailer(string $mailer, $notifiable, Notification $notification)
    {
        $previous = config('mail.default');

        try {
            config(['mail.default' => $mailer]);
            return parent::send($notifiable, $notification);
        } catch (\Throwable $e) {
            Log::error('[Mail] Fallback notification email failed.', [
                'notification' => class_basename($notification),
                'mailer'       => $mailer,
                'error'        => $e->getMessage(),
            ]);
        } finally {
            config(['mail.default' => $previous]);
        }
    }
}
