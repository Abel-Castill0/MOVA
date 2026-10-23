<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Synchronous: never persist the proof in a queue payload or log.
class AccountClosureCode extends Notification
{
    public function __construct(private string $code) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Confirma tu solicitud de eliminación — MOVA')
            ->line('Solicitaste confirmar tu identidad para cerrar tu cuenta en MOVA.')
            ->line('Tu código es: '.$this->code)
            ->line('Caduca en 10 minutos y se puede usar una sola vez. Si no lo solicitaste, no lo compartas.');
    }
}
