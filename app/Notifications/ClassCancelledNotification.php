<?php

namespace App\Notifications;

use App\Models\Lesson;
use App\Notifications\Concerns\BuildsAppUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClassCancelledNotification extends Notification implements ShouldQueue
{
    use Queueable, BuildsAppUrls;

    public function __construct(public Lesson $lesson) {}

    public function via($notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->email_verified_at) {
            $channels[] = 'mail';
        }
        if ($notifiable->phone_verified_at) {
            $channels[] = \App\Channels\WhatsAppChannel::class;
        }
        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        $date = $this->lesson->start_time->format('d/m/Y \a \l\a\s H:i');

        $mail = (new MailMessage)
            ->subject('Clase cancelada en MOVA')
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line('Le informamos que su clase programada ha sido **cancelada**.')
            ->line('**Fecha original:** ' . $date);

        if ($this->lesson->cancel_reason) {
            $mail->line('**Motivo:** ' . $this->lesson->cancel_reason);
        }

        return $mail
            ->line('Si tiene dudas o desea reagendar, puede contactar al equipo de MOVA.')
            ->action('Ver mis clases', $this->appUrl('/dashboard'))
            ->salutation('El equipo de MOVA');
    }

    public function toWhatsApp($notifiable): string
    {
        $date = $this->lesson->start_time->format('d/m/Y H:i');

        return "MOVA — Clase cancelada\n\n"
            . "Hola {$notifiable->name},\n"
            . "Su clase del {$date} ha sido cancelada.\n\n"
            . "Si tiene dudas, contáctenos a través de la plataforma MOVA.";
    }

    public function toArray($notifiable): array
    {
        return [
            'type'       => 'class_cancelled',
            'lesson_id'  => $this->lesson->id,
            'start_time' => $this->lesson->start_time->toISOString(),
            'message'    => 'Su clase del ' . $this->lesson->start_time->format('d/m/Y') . ' ha sido cancelada.',
        ];
    }
}
