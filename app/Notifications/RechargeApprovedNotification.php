<?php

namespace App\Notifications;

use App\Models\RechargeRequest;
use App\Notifications\Concerns\BuildsAppUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RechargeApprovedNotification extends Notification implements ShouldQueue
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
        return (new MailMessage)
            ->subject('Recarga de créditos aprobada - MOVA')
            ->greeting('Estimado/a ' . $notifiable->name . ',')
            ->line("Su recarga de {$this->recharge->credits} créditos ha sido aprobada exitosamente.")
            ->line('Ya puede utilizar su saldo para aceptar nuevas solicitudes de clases.')
            ->action('Ver mis créditos', $this->appRoute('teacher.credits.index'))
            ->salutation('El equipo de MOVA');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'recharge_approved',
            'recharge_request_id' => $this->recharge->id,
            'credits' => $this->recharge->credits,
        ];
    }
}
