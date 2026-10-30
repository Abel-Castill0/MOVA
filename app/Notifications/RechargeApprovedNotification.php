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
        $channels = ['mail'];
        if ($notifiable->phone_verified_at) {
            $channels[] = \App\Channels\WhatsAppChannel::class;
        }

        return $channels;
    }

    /** Aviso informativo: el crédito ya se asentó en el ledger; este mensaje nunca acredita ni confirma nada por sí mismo. */
    public function toWhatsApp(object $notifiable): string
    {
        return "MOVA — Recarga confirmada\n\n"
            . "Hola {$notifiable->name}, su recarga de {$this->recharge->credits} créditos fue confirmada.\n"
            . "Consulte su saldo en MOVA: " . $this->appRoute('teacher.credits.index');
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
            'message' => 'Su recarga de ' . $this->recharge->credits . ' créditos fue aprobada.',
        ];
    }
}
