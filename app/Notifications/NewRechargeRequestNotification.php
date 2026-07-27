<?php

namespace App\Notifications;

use App\Models\RechargeRequest;
use App\Notifications\Concerns\BuildsAppUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewRechargeRequestNotification extends Notification implements ShouldQueue
{
    use Queueable, BuildsAppUrls;

    public function __construct(private RechargeRequest $recharge)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->recharge->loadMissing('teacherProfile.user');

        $teacherName = $this->recharge->teacherProfile?->user?->name ?? 'Un profesor';
        $amount = number_format((float) $this->recharge->amount_pen, 2);

        return (new MailMessage)
            ->subject('Nueva solicitud de recarga de créditos - MOVA')
            ->greeting('Hola, Administrador.')
            ->line("El profesor {$teacherName} ha solicitado una recarga de {$this->recharge->credits} créditos por el monto de S/ {$amount}.")
            ->line("Número de Operación: {$this->recharge->operation_number}.")
            ->action('Revisar Recarga', $this->appRoute('admin.recharges.index'))
            ->salutation('El equipo de MOVA');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'new_recharge_request',
            'recharge_request_id' => $this->recharge->id,
            'credits' => $this->recharge->credits,
            'amount_pen' => $this->recharge->amount_pen,
            'message' => 'Nueva solicitud de recarga de ' . $this->recharge->credits . ' créditos.',
        ];
    }
}
