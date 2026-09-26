<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsAppUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avisa al profesor de que el padre rechazó su propuesta de horario. La
 * solicitud vuelve a 'open' (ver CounterofferController::reject()), así que
 * el profesor puede aceptarla con otro horario o proponer uno nuevo.
 *
 * Deliberadamente sin datos del alumno ni del padre: el profesor ya tiene
 * el id de la solicitud y la materia, que es todo lo que necesita.
 */
class CounterofferRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable, BuildsAppUrls;

    public function __construct(public int $classRequestId, public string $subjectName) {}

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
        return (new MailMessage)
            ->subject('MOVA — Su propuesta de horario no fue aceptada')
            ->greeting('Hola, '.$notifiable->name.'.')
            ->line("El padre no aceptó el horario que propuso para la clase de {$this->subjectName}.")
            ->line('La solicitud vuelve a estar disponible: puede aceptarla con otro horario o proponer uno nuevo.')
            ->action('Ver solicitudes', $this->appRoute('teacher.requests'))
            ->salutation('El equipo de MOVA');
    }

    public function toArray($notifiable): array
    {
        return [
            'type'             => 'counteroffer_rejected',
            'class_request_id' => $this->classRequestId,
            'message'          => "El padre no aceptó su propuesta de horario para la clase de {$this->subjectName}.",
        ];
    }
}
