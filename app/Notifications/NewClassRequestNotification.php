<?php

namespace App\Notifications;

use App\Models\ClassRequest;
use App\Notifications\Concerns\BuildsAppUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewClassRequestNotification extends Notification implements ShouldQueue
{
    use Queueable, BuildsAppUrls;

    public function __construct(public ClassRequest $classRequest) {}

    public function via($notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->email_verified_at) {
            $channels[] = 'mail';
        }
        return $channels;
    }

    /**
     * Re-evaluado al ENTREGAR (la notificación va encolada): lleva el nombre
     * del menor, así que si entre el encolado y el envío se retiró la
     * verificación del profesor, se le suspendió o la solicitud dejó de estar
     * abierta, no se envía por ningún canal.
     */
    public function shouldSend($notifiable, string $channel): bool
    {
        $request = $this->classRequest->fresh();
        $teacher = $notifiable->fresh();

        return $request !== null
            && $teacher !== null
            && $teacher->suspended_at === null
            && $request->status === 'open'
            && $request->isEligibleTeacherUser($teacher);
    }

    /**
     * Pre-aceptación: solo el nombre de pila del menor (misma minimización que
     * ClassRequestController::teacherRequestSummary). Esta notificación llega
     * a todos los profesores elegibles, no solo al que termine aceptando.
     */
    private function studentLabel(): string
    {
        return (string) $this->classRequest->student->first_name;
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Nueva solicitud de clase en MOVA')
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line('Colega, ha recibido una nueva solicitud de clase.')
            ->line('**Asignatura:** ' . $this->classRequest->subject->name)
            ->line('**Estudiante:** ' . $this->studentLabel())
            ->action('Ver solicitud', $this->appRoute('teacher.requests'))
            ->salutation('El equipo de MOVA');
    }

    public function toArray($notifiable): array
    {
        return [
            'type'       => 'new_class_request',
            'request_id' => $this->classRequest->id,
            'subject'    => $this->classRequest->subject->name,
            'student'    => $this->studentLabel(),
            'message'    => 'Nueva solicitud de ' . $this->classRequest->subject->name . ' de ' . $this->studentLabel() . '.',
        ];
    }
}
