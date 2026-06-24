<?php

namespace App\Notifications;

use App\Models\Lesson;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClassCancelledNotification extends Notification implements ShouldQueue
{
    use Queueable;

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

        return (new MailMessage)
            ->subject('Tu clase fue cancelada en MOVA')
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line('Lamentamos informarte que tu clase programada ha sido **cancelada**.')
            ->line('**Fecha original:** ' . $date)
            ->line('Si tienes dudas o deseas reagendar, puedes contactar al equipo de MOVA.')
            ->action('Ver mis clases', url('/'))
            ->salutation('El equipo de MOVA');
    }

    public function toWhatsApp($notifiable): string
    {
        $date = $this->lesson->start_time->format('d/m/Y H:i');

        return "MOVA — Clase cancelada\n\n"
            . "Hola {$notifiable->name},\n"
            . "Tu clase del {$date} ha sido cancelada.\n\n"
            . "Si tienes dudas, contáctanos a través de la plataforma MOVA.";
    }

    public function toArray($notifiable): array
    {
        return [
            'type'       => 'class_cancelled',
            'lesson_id'  => $this->lesson->id,
            'start_time' => $this->lesson->start_time->toISOString(),
            'message'    => 'Tu clase del ' . $this->lesson->start_time->format('d/m/Y') . ' ha sido cancelada.',
        ];
    }
}
