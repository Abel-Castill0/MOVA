<?php

namespace App\Notifications;

use App\Models\RechargeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RechargeRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private RechargeRequest $recharge, private ?string $reason = null)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Problema con su solicitud de recarga - MOVA')
            ->greeting('Estimado/a ' . $notifiable->name . ',')
            ->line("Hubo un inconveniente al procesar su solicitud de recarga de {$this->recharge->credits} créditos (Operación: {$this->recharge->operation_number}).")
            ->line('Por favor, verifique el número de operación ingresado o póngase en contacto con soporte.');

        if ($this->reason) {
            $message->line('Motivo registrado: ' . $this->reason);
        }

        return $message->salutation('El equipo de MOVA');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'recharge_rejected',
            'recharge_request_id' => $this->recharge->id,
            'credits' => $this->recharge->credits,
            'reason' => $this->reason,
            'message' => 'Hubo un problema con su solicitud de recarga de ' . $this->recharge->credits . ' créditos.',
        ];
    }
}
