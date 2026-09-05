<?php

namespace App\Notifications;

use App\Models\RechargeRequest;
use App\Notifications\Concerns\BuildsAppUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * §5 — El profesor se entera de que le retiraron créditos ya abonados.
 *
 * Ocurre cuando Mercado Pago confirma un reembolso total sobre un pago que ya
 * se había acreditado, o cuando un administrador revierte una recarga aprobada
 * por error. Antes el saldo simplemente bajaba: la única explicación estaba en
 * la etiqueta "Movimiento revertido" del historial de créditos, que solo ve
 * quien entra a mirarlo.
 *
 * QUÉ NO LLEVA, A PROPÓSITO:
 *
 * Ni el número de operación, ni el id del pago en el proveedor, ni el motivo
 * interno de la reversión. Un motivo interno puede contener terminología de
 * fraude o de contracargo escrita para un operador, no para el profesor. Lo que
 * el profesor necesita saber es qué paquete se revirtió, cuántos créditos se
 * descontaron y a dónde ir a verlo; para el resto, hay soporte.
 */
class RechargeReversedNotification extends Notification implements ShouldQueue
{
    use Queueable, BuildsAppUrls;

    public function __construct(public RechargeRequest $recharge)
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
        return (new MailMessage)
            ->subject('Se revirtió una recarga de créditos en MOVA')
            ->greeting('Hola, '.$notifiable->name.'.')
            ->line('Se revirtió la recarga del paquete **'.$this->recharge->package_name.'**.')
            ->line('Esto significa que se descontaron **'.$this->recharge->credits.' créditos** de tu saldo disponible.')
            ->line('Los créditos que ya estuvieran reservados por clases agendadas no se ven afectados: '
                .'esas clases siguen en pie.')
            ->action('Ver mi saldo y movimientos', $this->appRoute('teacher.credits.index'))
            ->line('Si crees que esto es un error, responde a este correo y lo revisamos.')
            ->salutation('El equipo de MOVA');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'recharge_reversed',
            'recharge_request_id' => $this->recharge->id,
            'credits' => $this->recharge->credits,
            'message' => 'Se revirtió una recarga de '.$this->recharge->credits.' créditos.',
        ];
    }
}
