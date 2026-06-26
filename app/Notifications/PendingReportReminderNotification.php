<?php

namespace App\Notifications;

use App\Models\Lesson;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PendingReportReminderNotification extends Notification implements ShouldQueue
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
        $lesson = $this->lesson;
        $lesson->loadMissing('classRequest.subject');
        $subject = $lesson->classRequest?->subject?->name ?? 'Clase';

        return (new MailMessage)
            ->subject('Reporte pendiente en MOVA')
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line("Tienes un reporte pendiente para la clase de **{$subject}**.")
            ->line('Completar el reporte ayuda a los padres a seguir el progreso de sus hijos.')
            ->action('Crear reporte ahora', route('lesson-reports.create', $lesson))
            ->salutation('El equipo de MOVA');
    }

    public function toWhatsApp($notifiable): string
    {
        $this->lesson->loadMissing('classRequest.subject');
        $subject = $this->lesson->classRequest?->subject?->name ?? 'Clase';

        return "MOVA — Reporte pendiente\n\n"
            . "Hola {$notifiable->name},\n"
            . "Tienes un reporte pendiente en MOVA para la clase de {$subject}.\n"
            . "Complétalo para informar al padre del progreso.";
    }

    public function toArray($notifiable): array
    {
        return [
            'type'      => 'pending_report_reminder',
            'lesson_id' => $this->lesson->id,
            'message'   => 'Tienes un reporte pendiente en MOVA.',
        ];
    }
}
