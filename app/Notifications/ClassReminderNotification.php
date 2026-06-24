<?php

namespace App\Notifications;

use App\Models\Lesson;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClassReminderNotification extends Notification implements ShouldQueue
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
        return (new MailMessage)
            ->subject('Recordatorio de clase en MOVA')
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line('Tu clase comienza en **menos de 10 minutos**.')
            ->line('**Hora:** ' . $this->lesson->start_time->format('H:i'))
            ->line('**Contraseña:** ' . ($this->lesson->zoom_password ?? 'Sin contraseña'))
            ->action('Entrar a Zoom ahora', $this->lesson->zoom_link ?? url('/'))
            ->salutation('El equipo de MOVA');
    }

    public function toWhatsApp($notifiable): string
    {
        return "MOVA — Recordatorio de clase\n\n"
            . "Hola {$notifiable->name}, tu clase empieza en menos de 10 minutos.\n"
            . "Hora: " . $this->lesson->start_time->format('H:i') . "\n"
            . ($this->lesson->zoom_link ?? 'Enlace no disponible') . "\n"
            . "Contraseña: " . ($this->lesson->zoom_password ?? 'Sin contraseña');
    }

    public function toArray($notifiable): array
    {
        return [
            'type'       => 'class_reminder',
            'lesson_id'  => $this->lesson->id,
            'start_time' => $this->lesson->start_time->toISOString(),
            'zoom_link'  => $this->lesson->zoom_link,
            'message'    => 'Tu clase empieza en menos de 10 minutos.',
        ];
    }
}
