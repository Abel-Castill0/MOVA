<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsAppUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso al profesor cuando una clase NO pudo agendarse por falta de créditos. Es solo informativo: no reserva, no
 * acredita ni modifica saldo. No incluye datos del alumno.
 */
class InsufficientCreditsNotification extends Notification implements ShouldQueue
{
    use Queueable, BuildsAppUrls;

    public function __construct(private float $needed, private float $available)
    {
        // Se encola después del commit: el worker no debe ver el aviso antes que los datos que lo originan.
        $this->afterCommit();
    }

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

    private function fmt(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Créditos insuficientes para agendar una clase - MOVA')
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line('No pudimos confirmar una clase porque su saldo no alcanza.')
            ->line('**Créditos necesarios:** ' . $this->fmt($this->needed))
            ->line('**Créditos disponibles:** ' . $this->fmt($this->available))
            ->action('Recargar créditos', $this->appRoute('teacher.credits.index'))
            ->salutation('El equipo de MOVA');
    }

    public function toWhatsApp($notifiable): string
    {
        return "MOVA — Créditos insuficientes\n\n"
            . "Hola {$notifiable->name}, no pudimos confirmar una clase: necesita {$this->fmt($this->needed)} créditos y tiene {$this->fmt($this->available)}.\n"
            . "Recargue en MOVA: " . $this->appRoute('teacher.credits.index');
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'insufficient_credits',
            'needed' => $this->needed,
            'available' => $this->available,
            'message' => 'No se pudo agendar una clase: créditos insuficientes (necesita ' . $this->fmt($this->needed) . ', tiene ' . $this->fmt($this->available) . ').',
        ];
    }
}
