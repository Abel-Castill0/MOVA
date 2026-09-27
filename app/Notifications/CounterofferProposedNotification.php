<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Models\ClassRequest;
use App\Notifications\Concerns\BuildsAppUrls;
use App\Support\LimaClock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avisa al padre de que un profesor propuso otro horario para su solicitud.
 *
 * UTILITY, no OTP: pasa por el mismo WhatsAppChannel que el resto de avisos
 * (WHATSAPP_ENABLED, teléfono verificado, consentimiento explícito, cuenta
 * suspendida, fila de auditoría). La respuesta del padre NUNCA viaja por
 * WhatsApp — aceptar o rechazar ocurre solo dentro de MOVA, autenticado y
 * con ownership verificado (CounterofferController). El mensaje solo invita
 * a entrar; no pide responder "SI/NO".
 *
 * Solo datos que el padre ya puede ver de su propia solicitud: nombre del
 * profesor, materia, fecha/hora y duración propuestas. Nada de contacto del
 * profesor ni datos de pago.
 */
class CounterofferProposedNotification extends Notification implements ShouldQueue
{
    use Queueable, BuildsAppUrls;

    public function __construct(public ClassRequest $classRequest) {}

    public function via($notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->email_verified_at) {
            $channels[] = 'mail';
        }
        if ($notifiable->phone_verified_at) {
            $channels[] = WhatsAppChannel::class;
        }

        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('MOVA — Un profesor propone otro horario para su clase')
            ->greeting('Hola, '.$notifiable->name.'.')
            ->line($this->summary())
            ->line('Puede aceptar o rechazar la propuesta desde sus solicitudes en MOVA.')
            ->action('Revisar propuesta', $this->appRoute('class-requests.index'))
            ->salutation('El equipo de MOVA');
    }

    public function toWhatsApp($notifiable): string
    {
        return "MOVA — Nueva propuesta de horario\n\n"
            .$this->summary()."\n"
            ."Ingrese a MOVA para aceptarla o rechazarla:\n"
            .$this->appRoute('class-requests.index');
    }

    public function toArray($notifiable): array
    {
        return [
            'type'             => 'counteroffer_proposed',
            'class_request_id' => $this->classRequest->id,
            'message'          => $this->summary(),
        ];
    }

    private function summary(): string
    {
        $this->classRequest->loadMissing(['subject', 'counterofferTeacherProfile.user']);

        $teacher = $this->classRequest->counterofferTeacherProfile?->user?->name ?? 'Un profesor';
        $subject = $this->classRequest->subject?->name ?? 'su clase';
        $when = $this->classRequest->counteroffer_time
            ?->copy()->setTimezone(LimaClock::TIMEZONE)
            ->format('d/m/Y \a \l\a\s H:i');
        $minutes = (int) $this->classRequest->counteroffer_duration_minutes;

        return "{$teacher} propone la clase de {$subject} el {$when} (hora de Perú), con una duración de {$minutes} minutos.";
    }
}
