<?php

namespace App\Notifications;

use App\Models\Lesson;
use App\Notifications\Concerns\BuildsAppUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClassReminderNotification extends Notification implements ShouldQueue
{
    use Queueable, BuildsAppUrls;

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

    // Nunca enlazamos la sala directamente: jitsi_room es un token de acceso
    // (ver Lesson::$hidden) y su único punto de revelación autorizado es
    // LessonController::join(), que valida policy y estado. El correo lleva al
    // listado de clases dentro de MOVA, donde el usuario ya autenticado entra
    // por ese flujo. Esta notificación va tanto al padre como al profesor, así
    // que el destino se resuelve por el rol del destinatario.
    private function classListUrl($notifiable): string
    {
        return $notifiable->hasRole('teacher')
            ? $this->appRoute('teacher.lessons')
            : $this->appRoute('parent.lessons');
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject())
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line("Su clase comienza **{$this->label()}**.")
            ->line('**Hora:** ' . $this->lesson->start_time->format('d/m/Y H:i'))
            ->action('Ver mi clase en MOVA', $this->classListUrl($notifiable))
            ->line('Entre a la sala desde MOVA unos minutos antes de empezar.')
            ->salutation('El equipo de MOVA');
    }

    public function toWhatsApp($notifiable): string
    {
        return "MOVA — Recordatorio de clase\n\n"
            . "Hola {$notifiable->name}, su clase empieza {$this->label()}.\n"
            . "Hora: " . $this->lesson->start_time->format('d/m/Y H:i') . "\n"
            . "Entre a la sala desde MOVA: " . $this->classListUrl($notifiable);
    }

    public function toArray($notifiable): array
    {
        return [
            'type'       => 'class_reminder',
            'interval'   => $this->interval,
            'lesson_id'  => $this->lesson->id,
            'start_time' => $this->lesson->start_time->toISOString(),
            'message'    => "Su clase empieza {$this->label()}.",
        ];
    }
}
