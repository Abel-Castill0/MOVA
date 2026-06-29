<?php

namespace App\Notifications;

use App\Models\Lesson;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClassRescheduledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Lesson $lesson, public string $changedBy) {}

    public function via($notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->email_verified_at) {
            $channels[] = 'mail';
        }
        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        $newDate     = $this->lesson->start_time->format('d/m/Y \a \l\a\s H:i');
        $originalDate = $this->lesson->original_start_time
            ? $this->lesson->original_start_time->format('d/m/Y H:i')
            : '—';

        $mail = (new MailMessage)
            ->subject('Tu clase ha sido reprogramada en MOVA')
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line('Tu clase ha sido reprogramada por ' . $this->changedBy . '.')
            ->line('**Fecha original:** ' . $originalDate)
            ->line('**Nueva fecha:** ' . $newDate);

        if ($this->lesson->reschedule_reason) {
            $mail->line('**Motivo:** ' . $this->lesson->reschedule_reason);
        }

        return $mail
            ->action('Ver mis clases', url('/'))
            ->salutation('El equipo de MOVA');
    }

    public function toArray($notifiable): array
    {
        $newDate = $this->lesson->start_time->format('d/m/Y H:i');
        return [
            'type'       => 'class_rescheduled',
            'lesson_id'  => $this->lesson->id,
            'new_date'   => $newDate,
            'message'    => "Tu clase ha sido reprogramada para el {$newDate}.",
        ];
    }
}
