<?php

namespace App\Notifications;

use App\Models\Lesson;
use App\Notifications\Concerns\BuildsAppUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PendingReportReminderNotification extends Notification implements ShouldQueue
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
        $lesson = $this->lesson;
        $lesson->loadMissing('classRequest.subject');
        $subject = $lesson->classRequest?->subject?->name ?? 'Clase';

        return (new MailMessage)
            ->subject('Reporte pendiente en MOVA')
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line("Colega, tiene un reporte pendiente para la clase de **{$subject}**.")
            ->line('Completar el reporte ayuda a las familias a seguir el progreso académico de sus hijos.')
            ->action('Crear reporte ahora', $this->appRoute('lesson-reports.create', $lesson))
            ->salutation('El equipo de MOVA');
    }

    public function toWhatsApp($notifiable): string
    {
        $this->lesson->loadMissing('classRequest.subject');
        $subject = $this->lesson->classRequest?->subject?->name ?? 'Clase';

        return "MOVA — Reporte pendiente\n\n"
            . "Hola {$notifiable->name},\n"
            . "Tiene un reporte pendiente en MOVA para la clase de {$subject}.\n"
            . "Complételo para informar a la familia sobre el progreso del estudiante.";
    }

    public function toArray($notifiable): array
    {
        return [
            'type'      => 'pending_report_reminder',
            'lesson_id' => $this->lesson->id,
            'message'   => 'Tiene un reporte pendiente en MOVA.',
        ];
    }
}
