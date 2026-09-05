<?php

namespace App\Notifications;

use App\Models\ClassRequest;
use App\Notifications\Concerns\BuildsAppUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * §14 — El padre se entera de que su solicitud caducó.
 *
 * Es el único destinatario: es quien pierde algo (esperaba una clase que ya no
 * va a llegar) y quien puede hacer algo al respecto (volver a solicitarla).
 *
 * A los profesores no se les avisa a propósito. Para ellos la solicitud era una
 * que decidieron no atender; mandar un aviso de expiración a todos los
 * profesores elegibles de la materia convertiría cada caducidad en N correos
 * sobre algo que nadie tiene que hacer.
 *
 * El tono importa: la solicitud caducó porque ningún profesor la tomó, y decirlo
 * así —sin culpar al padre— es tanto más honesto como más útil, porque sugiere
 * qué cambiar (ampliar horarios, otra materia) en el siguiente intento.
 */
class ClassRequestExpiredNotification extends Notification implements ShouldQueue
{
    use Queueable, BuildsAppUrls;

    public function __construct(public ClassRequest $classRequest)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable->email_verified_at) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->classRequest->subject?->name ?? 'la materia solicitada';
        $student = $this->classRequest->student?->first_name;

        return (new MailMessage)
            ->subject('Tu solicitud de clase caducó — MOVA')
            ->greeting('Hola, '.$notifiable->name.'.')
            ->line($student
                ? "Tu solicitud de clase de **{$subject}** para {$student} caducó porque ningún profesor la tomó a tiempo."
                : "Tu solicitud de clase de **{$subject}** caducó porque ningún profesor la tomó a tiempo.")
            ->line('No se te ha cobrado nada y puedes volver a solicitarla cuando quieras.')
            ->line('Si amplías los horarios disponibles o describes con más detalle lo que necesitas, '
                .'es más probable que un profesor la acepte.')
            ->action('Crear una nueva solicitud', $this->appRoute('class-requests.create'))
            ->salutation('El equipo de MOVA');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'class_request_expired',
            'class_request_id' => $this->classRequest->id,
            'message' => 'Tu solicitud de clase caducó sin que ningún profesor la tomara.',
        ];
    }
}
