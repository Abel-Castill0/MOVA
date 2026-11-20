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

    /**
     * @param  string  $reason  no_answer | slot_passed | approval_missing | proposal_lapsed (ExpireClassRequests)
     */
    public function __construct(public ClassRequest $classRequest, public string $reason = 'no_answer')
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

    /** Frase principal, sin culpar al padre y diciendo la causa real. */
    private function sentence(): string
    {
        $subject = $this->classRequest->subject?->name ?? 'la materia solicitada';
        $student = $this->classRequest->student?->first_name;
        $for = $student ? " para {$student}" : '';
        $when = \App\Support\RequestSlots::describe($this->classRequest->preferred_date, $this->classRequest->preferred_slot);

        return match ($this->reason) {
            'slot_passed' => "Tu solicitud de clase de **{$subject}**{$for} para el {$when} terminó sin una clase confirmada: ningún profesor propuso un horario a tiempo.",
            'approval_missing' => "Tu solicitud de clase de **{$subject}**{$for} para el {$when} terminó sin publicarse: quedó pendiente de tu aprobación hasta que pasó la franja.",
            'proposal_lapsed' => "Tu solicitud de clase de **{$subject}**{$for} terminó: la propuesta de horario del profesor pasó sin respuesta y ya no quedaba tiempo para otra.",
            default => "Tu solicitud de clase de **{$subject}**{$for} caducó porque ningún profesor la tomó a tiempo.",
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Tu solicitud de clase terminó — MOVA')
            ->greeting('Hola, '.$notifiable->name.'.')
            ->line($this->sentence())
            ->line('No se te ha cobrado nada y puedes volver a solicitarla cuando quieras.')
            ->line('Si eliges otro día o franja, o describes con más detalle lo que necesitas, '
                .'es más probable que un profesor te proponga un horario.')
            ->action('Crear una nueva solicitud', $this->appRoute('class-requests.create'))
            ->salutation('El equipo de MOVA');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'class_request_expired',
            'class_request_id' => $this->classRequest->id,
            'reason' => $this->reason,
            'message' => str_replace('**', '', $this->sentence()),
        ];
    }
}
