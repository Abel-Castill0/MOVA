<?php

namespace App\Notifications;

use App\Models\Lesson;
use App\Notifications\Concerns\BuildsAppUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * C-1, Decisión de negocio #3 (Fase 3B §17) — el cierre automático de una
 * clase (LessonSettlementService::consume() disparado por mova:settle-lessons
 * tras la ventana de gracia) es la ÚNICA liquidación que antes no avisaba a
 * nadie: el profesor vería su crédito consumirse sin explicación.
 *
 * Deliberadamente SOLO para ese camino — no se dispara cuando la reseña o un
 * admin liquidan la clase, porque esas acciones ya tienen su propia
 * notificación (TeacherReviewReceivedNotification, o simplemente ninguna
 * porque el actor ya sabe lo que hizo). Ver
 * LessonSettlementService::consume(..., notify: true), usado únicamente por
 * SettleLessons.
 *
 * Canales deliberadamente mínimos (database + mail, sin WhatsApp ni
 * broadcast): es una confirmación informativa, no una alerta urgente — no
 * amerita el mismo canal inmediato que un recordatorio de clase.
 *
 * NUNCA debe incluir jitsi_room/jitsi_password ni una URL de meet.jit.si —
 * mismo riesgo que C-3 (533a799): un token de acceso a una videollamada con
 * un menor no debe viajar en el payload de una notificación.
 */
class LessonSettledNotification extends Notification implements ShouldQueue
{
    use Queueable, BuildsAppUrls;

    public function __construct(public Lesson $lesson) {}

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
        $subject = $this->lesson->classRequest?->subject?->name ?? 'la clase';

        if ($notifiable->hasRole('teacher')) {
            return (new MailMessage)
                ->subject('Clase cerrada automáticamente en MOVA')
                ->greeting('Hola, '.$notifiable->name.'.')
                ->line("Tu clase de **{$subject}** se cerró automáticamente. El pago fue confirmado y el crédito ya se liquidó.")
                ->action('Ver mis clases', $this->appRoute('teacher.lessons'))
                ->salutation('El equipo de MOVA');
        }

        $student = $this->lesson->student?->first_name ?? 'su hijo/a';

        return (new MailMessage)
            ->subject('Clase completada en MOVA')
            ->greeting('Hola, '.$notifiable->name.'.')
            ->line("La clase de **{$subject}** de {$student} se completó automáticamente. Puede calificar al profesor cuando quiera.")
            ->action('Ver mis clases', $this->appRoute('parent.lessons'))
            ->salutation('El equipo de MOVA');
    }

    public function toArray($notifiable): array
    {
        $subject = $this->lesson->classRequest?->subject?->name ?? 'Clase';

        return [
            'type' => 'lesson_settled',
            'lesson_id' => $this->lesson->id,
            'subject' => $subject,
            'message' => $notifiable->hasRole('teacher')
                ? "Tu clase de {$subject} se cerró automáticamente."
                : "La clase de {$subject} se completó automáticamente.",
        ];
    }
}
