<?php

namespace App\Notifications;

use App\Models\Lesson;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClassConfirmedNotification extends Notification implements ShouldQueue
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
            ->subject('Clase confirmada en MOVA')
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line('Su clase ha sido **confirmada** correctamente.')
            ->line('**Fecha:** ' . $date)
            ->line('**Duración:** ' . $this->lesson->duration_minutes . ' minutos')
            ->line('**Contraseña Zoom:** ' . ($this->lesson->zoom_password ?? 'Sin contraseña'))
            ->action('Entrar a la clase por Zoom', $this->lesson->zoom_link ?? url('/'))
            ->line('Conserve este enlace. Recibirá un recordatorio 10 minutos antes de la clase.')
            ->salutation('El equipo de MOVA');
    }

    public function toWhatsApp($notifiable): string
    {
        $date = $this->lesson->start_time->format('d/m/Y H:i');

        return "MOVA — Clase confirmada\n\n"
            . "Hola {$notifiable->name},\n"
            . "Fecha: {$date}\n"
            . "Duración: {$this->lesson->duration_minutes} min\n"
            . "Zoom: " . ($this->lesson->zoom_link ?? 'No disponible') . "\n"
            . "Contraseña: " . ($this->lesson->zoom_password ?? 'Sin contraseña') . "\n\n"
            . "Recibirá un recordatorio 10 minutos antes.";
    }

    public function toArray($notifiable): array
    {
        return [
            'type'       => 'class_confirmed',
            'lesson_id'  => $this->lesson->id,
            'start_time' => $this->lesson->start_time->toISOString(),
            'zoom_link'  => $this->lesson->zoom_link,
            'message'    => 'Su clase del ' . $this->lesson->start_time->format('d/m/Y') . ' ha sido confirmada.',
        ];
    }
}
