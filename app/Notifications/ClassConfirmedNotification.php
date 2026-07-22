<?php

namespace App\Notifications;

use App\Models\Lesson;
use App\Notifications\Concerns\BuildsAppUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClassConfirmedNotification extends Notification implements ShouldQueue
{
    use Queueable, BuildsAppUrls;

    public function __construct(public Lesson $lesson) {}

    public function via($notifiable): array
    {
        $channels = ['database', 'broadcast'];
        if ($notifiable->email_verified_at) {
            $channels[] = 'mail';
        }
        if ($notifiable->phone_verified_at) {
            $channels[] = \App\Channels\WhatsAppChannel::class;
        }
        return $channels;
    }

    private function jitsiUrl(): ?string
    {
        return $this->lesson->jitsi_room ? 'https://meet.jit.si/'.$this->lesson->jitsi_room : null;
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
            ->action('Entrar a la Sala Virtual', $this->jitsiUrl() ?? $this->appUrl('/dashboard'))
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
            . "Sala Virtual: " . ($this->jitsiUrl() ?? 'No disponible') . "\n\n"
            . "Recibirá un recordatorio 10 minutos antes.";
    }

    public function toArray($notifiable): array
    {
        return [
            'type'       => 'class_confirmed',
            'lesson_id'  => $this->lesson->id,
            'start_time' => $this->lesson->start_time->toISOString(),
            'jitsi_url'  => $this->jitsiUrl(),
            'message'    => 'Su clase del ' . $this->lesson->start_time->format('d/m/Y') . ' ha sido confirmada.',
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
