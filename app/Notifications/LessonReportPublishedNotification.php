<?php

namespace App\Notifications;

use App\Models\LessonReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LessonReportPublishedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public LessonReport $report) {}

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
        $student = $this->report->student;
        $subject = $this->report->lesson->classRequest?->subject?->name ?? 'la clase';

        return (new MailMessage)
            ->subject('Nuevo reporte de aprendizaje en MOVA')
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line('El profesor ha enviado el reporte de la clase de **' . $subject . '** para ' . ($student->first_name ?? 'su hijo/a') . '.')
            ->line('**Tema trabajado:** ' . $this->report->topic_covered)
            ->line('**Desempeño:** ' . $this->report->student_performance)
            ->when($this->report->homework_assigned, fn ($mail) =>
                $mail->line('**Tarea asignada:** ' . $this->report->homework_assigned)
            )
            ->when($this->report->next_step, fn ($mail) =>
                $mail->line('**Próximo paso:** ' . $this->report->next_step)
            )
            ->action('Ver reporte completo', url('/my-classes'))
            ->salutation('El equipo de MOVA');
    }

    public function toWhatsApp($notifiable): string
    {
        $subject = $this->report->lesson->classRequest?->subject?->name ?? 'la clase';
        $student = $this->report->student->first_name ?? 'su hijo/a';

        return "MOVA — Reporte de aprendizaje\n\n"
            . "Hola {$notifiable->name},\n"
            . "Tiene un nuevo reporte de aprendizaje en MOVA para la clase de {$subject} de {$student}.\n"
            . "Puede revisarlo en su panel.";
    }

    public function toArray($notifiable): array
    {
        $subject = $this->report->lesson->classRequest?->subject?->name ?? 'Clase';
        $student = $this->report->student->first_name ?? '';

        return [
            'type'       => 'lesson_report_published',
            'report_id'  => $this->report->id,
            'lesson_id'  => $this->report->lesson_id,
            'student'    => $student,
            'subject'    => $subject,
            'message'    => "Nuevo reporte de {$subject} para {$student}.",
        ];
    }
}
