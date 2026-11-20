<?php

namespace App\Notifications;

use App\Models\ClassRequest;
use App\Notifications\Concerns\BuildsAppUrls;
use App\Support\LimaClock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Una propuesta de horario pasó sin respuesta del padre (ExpireClassRequests). Nunca se confirmó sola.
 *   padre:    si aún hay oportunidad, su solicitud sigue abierta para otros horarios; si no, recibe además el aviso de fin.
 *   profesor: su propuesta ya no está vigente (solo el profesor que la hizo; nunca todos los elegibles).
 * Sin WhatsApp: es informativo y evita mensajes de más; queda en la campana y, con correo verificado, por correo.
 */
class CounterofferLapsedNotification extends Notification implements ShouldQueue
{
    use Queueable, BuildsAppUrls;

    public function __construct(public ClassRequest $classRequest, public bool $stillOpen, public string $audience)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->email_verified_at ? ['database', 'mail'] : ['database'];
    }

    private function text(): string
    {
        $subject = $this->classRequest->subject?->name ?? 'la clase';
        $deadline = $this->classRequest->responseDeadline();

        if ($this->audience === 'teacher') {
            return "Tu propuesta de horario para la clase de {$subject} pasó sin respuesta de la familia y ya no está vigente."
                .($this->stillOpen ? ' La solicitud sigue abierta: puedes proponer otro horario si aún te viene bien.' : '');
        }

        return $this->stillOpen && $deadline
            ? "La propuesta de horario para la clase de {$subject} pasó sin respuesta. Tu solicitud sigue abierta y puede recibir otra propuesta hasta el ".LimaClock::dateTimeLong($deadline).'.'
            : "La propuesta de horario para la clase de {$subject} pasó sin respuesta.";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Una propuesta de horario venció — MOVA')
            ->greeting('Hola, '.$notifiable->name.'.')
            ->line($this->text())
            ->line('No se agendó ninguna clase ni se movieron créditos.')
            ->action('Ver en MOVA', $this->appRoute($this->audience === 'teacher' ? 'teacher.requests' : 'class-requests.index'))
            ->salutation('El equipo de MOVA');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'counteroffer_lapsed',
            'class_request_id' => $this->classRequest->id,
            'still_open' => $this->stillOpen,
            'message' => $this->text(),
        ];
    }
}
