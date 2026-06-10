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
        return ['mail', 'database', \App\Channels\WhatsAppChannel::class];
    }

    public function toMail($notifiable): MailMessage
    {
        $date = $this->lesson->start_time->format('d/m/Y \a \l\a\s H:i');

        return (new MailMessage)
            ->subject('✅ Clase confirmada – MOVA')
            ->greeting('¡Hola, ' . $notifiable->name . '!')
            ->line('Tu clase ha sido **confirmada** correctamente.')
            ->line('📅 **Fecha:** ' . $date)
            ->line('⏱ **Duración:** ' . $this->lesson->duration_minutes . ' minutos')
            ->line('🔑 **Contraseña Zoom:** ' . ($this->lesson->zoom_password ?? 'Sin contraseña'))
            ->action('Entrar a la clase por Zoom', $this->lesson->zoom_link ?? '#')
            ->line('Guarda este enlace. 10 minutos antes recibirás un recordatorio.')
            ->salutation('El equipo de MOVA');
    }

    public function toWhatsApp($notifiable): string
    {
        $date = $this->lesson->start_time->format('d/m/Y H:i');

        return "✅ *Clase confirmada – MOVA*\n\n"
            . "Hola {$notifiable->name},\n"
            . "📅 Fecha: {$date}\n"
            . "⏱ Duración: {$this->lesson->duration_minutes} min\n"
            . "🔗 Zoom: " . ($this->lesson->zoom_link ?? 'No disponible') . "\n"
            . "🔑 Contraseña: " . ($this->lesson->zoom_password ?? '—') . "\n\n"
            . "Recibirás un recordatorio 10 minutos antes.";
    }

    public function toArray($notifiable): array
    {
        return [
            'type'       => 'class_confirmed',
            'lesson_id'  => $this->lesson->id,
            'start_time' => $this->lesson->start_time->toISOString(),
            'zoom_link'  => $this->lesson->zoom_link,
            'message'    => 'Tu clase del ' . $this->lesson->start_time->format('d/m/Y') . ' ha sido confirmada.',
        ];
    }
}
