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

    public function __construct(public Lesson $lesson, public string $interval = '10m') {}

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

    private function label(): string
    {
        return match ($this->interval) {
            '24h' => 'mañana',
            '2h'  => 'en 2 horas',
            default => 'en menos de 10 minutos',
        };
    }

    private function subject(): string
    {
        return match ($this->interval) {
            '24h' => 'Su clase en MOVA es mañana',
            '2h'  => 'Su clase en MOVA empieza en 2 horas',
            default => 'Su clase en MOVA empieza en 10 minutos',
        };
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject())
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line("Su clase comienza **{$this->label()}**.")
            ->line('**Hora:** ' . $this->lesson->start_time->format('d/m/Y H:i'))
            ->line('**Contraseña:** ' . ($this->lesson->zoom_password ?? 'Sin contraseña'))
            ->action('Entrar a Zoom', $this->lesson->zoom_link ?? url('/'))
            ->salutation('El equipo de MOVA');
    }

    public function toWhatsApp($notifiable): string
    {
        return "MOVA — Recordatorio de clase\n\n"
            . "Hola {$notifiable->name}, su clase empieza {$this->label()}.\n"
            . "Hora: " . $this->lesson->start_time->format('d/m/Y H:i') . "\n"
            . ($this->lesson->zoom_link ?? 'Enlace no disponible') . "\n"
            . "Contraseña: " . ($this->lesson->zoom_password ?? 'Sin contraseña');
    }

    public function toArray($notifiable): array
    {
        return [
            'type'       => 'class_reminder',
            'interval'   => $this->interval,
            'lesson_id'  => $this->lesson->id,
            'start_time' => $this->lesson->start_time->toISOString(),
            'zoom_link'  => $this->lesson->zoom_link,
            'message'    => "Su clase empieza {$this->label()}.",
        ];
    }
}
