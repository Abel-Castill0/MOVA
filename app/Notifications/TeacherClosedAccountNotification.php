<?php

namespace App\Notifications;

use App\Models\ClassRequest;
use App\Notifications\Concerns\BuildsAppUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * El profesor de una solicitud cerró su cuenta. Solo avisa al padre, que es quien decide:
 * - republished = true: su solicitud volvió a la búsqueda de profesor con las mismas condiciones.
 * - republished = false: la solicitud dependía de ese profesor (código u oferta); debe confirmar una nueva.
 */
class TeacherClosedAccountNotification extends Notification implements ShouldQueue
{
    use Queueable, BuildsAppUrls;

    public function __construct(public ClassRequest $classRequest, public bool $republished)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->email_verified_at ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->classRequest->subject?->name ?? 'la materia solicitada';
        $mail = (new MailMessage)->subject('Tu profesor ya no está disponible — MOVA')
            ->greeting('Hola, '.$notifiable->name.'.')
            ->line("El profesor de tu solicitud de **{$subject}** cerró su cuenta en MOVA.");

        return $this->republished
            ? $mail->line('Tu solicitud volvió a publicarse con las mismas condiciones para que otro profesor la acepte. No se te ha cobrado nada.')
                ->action('Ver mis solicitudes', $this->appRoute('class-requests.index'))->salutation('El equipo de MOVA')
            : $mail->line('Como tu solicitud era para ese profesor, no la publicamos por tu cuenta: confirma una nueva solicitud cuando quieras.')
                ->action('Crear una nueva solicitud', $this->appRoute('class-requests.create'))->salutation('El equipo de MOVA');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->republished ? 'class_request_republished' : 'class_request_needs_confirmation',
            'class_request_id' => $this->classRequest->id,
            'message' => $this->republished
                ? 'Tu profesor cerró su cuenta; tu solicitud volvió a la búsqueda de profesor.'
                : 'Tu profesor cerró su cuenta; confirma una nueva solicitud si aún la necesitas.',
        ];
    }
}
