<?php

namespace App\Notifications;

use App\Models\ClassRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UnansweredRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ClassRequest $classRequest) {}

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
        $subject = $this->classRequest->subject?->name ?? 'una clase';
        return (new MailMessage)
            ->subject('Tienes una solicitud pendiente en MOVA')
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line("Un padre solicitó una clase de **{$subject}** hace más de 12 horas.")
            ->line('Responde pronto para no perder la oportunidad.')
            ->action('Ver solicitud', url('/teacher/requests'))
            ->salutation('El equipo de MOVA');
    }

    public function toWhatsApp($notifiable): string
    {
        $subject = $this->classRequest->subject?->name ?? 'una clase';
        return "MOVA — Solicitud pendiente\n\n"
            . "Hola {$notifiable->name},\n"
            . "Un padre solicitó una clase de {$subject} hace más de 12 horas.\n"
            . "Responde pronto desde tu panel:\n"
            . url('/teacher/requests');
    }

    public function toArray($notifiable): array
    {
        return [
            'type'             => 'unanswered_request',
            'class_request_id' => $this->classRequest->id,
            'subject'          => $this->classRequest->subject?->name,
            'message'          => 'Tienes una solicitud de clase sin responder hace más de 12 horas.',
        ];
    }
}
