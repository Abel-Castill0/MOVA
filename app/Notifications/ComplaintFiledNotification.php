<?php

namespace App\Notifications;

use App\Models\Complaint;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** P0-K — constancia de la hoja de reclamación y, luego, de su respuesta. */
class ComplaintFiledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Complaint $complaint, public bool $answered = false) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $c = $this->complaint;
        $provider = config('legal.provider.business_name') ?: config('app.name');

        if ($this->answered) {
            return (new MailMessage)
                ->subject("Respuesta a tu {$c->type} {$c->code}")
                ->greeting("Hola {$c->consumer_name},")
                ->line("{$provider} ha respondido tu {$c->type} {$c->code}:")
                ->line($c->response)
                ->line('Fecha de respuesta: '.$c->responded_at?->format('d/m/Y H:i'));
        }

        return (new MailMessage)
            ->subject("Constancia de tu {$c->type} {$c->code} — Libro de Reclamaciones")
            ->greeting("Hola {$c->consumer_name},")
            ->line("Registramos tu {$c->type} con el código {$c->code} el ".$c->created_at->format('d/m/Y H:i').'.')
            ->line('Bien contratado: '.$c->good_type.' — '.$c->good_description.($c->amount !== null ? " (S/ {$c->amount})" : ''))
            ->line('Detalle: '.$c->detail)
            ->line('Pedido: '.$c->consumer_request)
            ->line('Responderemos en un plazo máximo de '.config('legal.complaint_response_days').' días hábiles.');
    }
}
