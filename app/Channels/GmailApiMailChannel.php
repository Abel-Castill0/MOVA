<?php

namespace App\Channels;

use App\Services\GmailApiMailService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class GmailApiMailChannel
{
    public function __construct(private GmailApiMailService $gmail) {}

    public function send($notifiable, Notification $notification): bool
    {
        if (!method_exists($notification, 'toMail')) {
            return true;
        }

        $to = $notifiable->routeNotificationFor('mail', $notification)
            ?? ($notifiable->email ?? null);

        if (!$to) {
            Log::warning('[Gmail] No email address for notifiable.', [
                'notification' => class_basename($notification),
            ]);
            return false;
        }

        try {
            /** @var MailMessage $message */
            $message = $notification->toMail($notifiable);
            $subject = $message->subject ?? 'Notificación MOVA';
            $html    = $this->buildHtml($message);

            $sent = $this->gmail->send($to, $notifiable->name ?? '', $subject, $html);

            if (!$sent) {
                Log::warning('[Gmail] Email not delivered.', [
                    'notification' => class_basename($notification),
                    'to'           => $to,
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('[Gmail] Error preparing notification email.', [
                'notification' => class_basename($notification),
                'error'        => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function buildHtml(MailMessage $message): string
    {
        $greeting   = e($message->greeting ?? 'Hola');
        $lines      = array_merge($message->introLines ?? [], $message->outroLines ?? []);
        $salutation = e($message->salutation ?? 'El equipo de MOVA');

        $linesHtml = implode('', array_map(
            fn ($line) => '<p style="margin:0 0 12px;line-height:1.6">'
                . nl2br(preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', e((string) $line)))
                . '</p>',
            $lines
        ));

        $actionHtml = '';
        if ($message->actionText && $message->actionUrl) {
            $url  = e($message->actionUrl);
            $text = e($message->actionText);
            $actionHtml = <<<HTML
            <p style="margin:24px 0">
              <a href="{$url}" style="background-color:#4f46e5;color:#ffffff;padding:12px 28px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:600;font-size:14px">{$text}</a>
            </p>
            HTML;
        }

        return <<<HTML
        <!DOCTYPE html>
        <html lang="es">
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width,initial-scale=1">
          <title>MOVA</title>
        </head>
        <body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif">
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr><td align="center" style="padding:32px 16px">
              <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%">
                <tr>
                  <td style="background:#4f46e5;border-radius:8px 8px 0 0;padding:20px 28px">
                    <span style="color:#ffffff;font-size:22px;font-weight:700;letter-spacing:-0.5px">MOVA</span>
                  </td>
                </tr>
                <tr>
                  <td style="background:#ffffff;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px;padding:28px 32px">
                    <p style="font-size:16px;font-weight:600;margin:0 0 16px;color:#111827">{$greeting}</p>
                    {$linesHtml}
                    {$actionHtml}
                    <hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0">
                    <p style="color:#6b7280;font-size:13px;margin:0">{$salutation}</p>
                  </td>
                </tr>
                <tr>
                  <td style="padding:16px 0;text-align:center">
                    <p style="color:#9ca3af;font-size:12px;margin:0 0 4px">MOVA — Plataforma de clases particulares</p>
                    <p style="color:#9ca3af;font-size:11px;margin:0">Este correo fue enviado porque tienes una cuenta registrada en MOVA.<br>Si no reconoces este mensaje, puedes ignorarlo.</p>
                  </td>
                </tr>
              </table>
            </td></tr>
          </table>
        </body>
        </html>
        HTML;
    }
}
