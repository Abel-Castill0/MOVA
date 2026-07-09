<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsAppUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeParentNotification extends Notification implements ShouldQueue
{
    use Queueable, BuildsAppUrls;

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
        return (new MailMessage)
            ->subject('Le damos la bienvenida a MOVA')
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line('Gracias por unirse a MOVA. Para comenzar:')
            ->line('**1.** Agregue a su hijo/a en su perfil.')
            ->line('**2.** Explore el marketplace y elija un profesor.')
            ->line('**3.** Solicite una clase directamente desde la oferta del profesor.')
            ->line('**4.** Recibirá recordatorios antes de cada clase y un reporte de aprendizaje al finalizar.')
            ->action('Ir al panel', $this->appUrl('/dashboard'))
            ->salutation('El equipo de MOVA');
    }

    public function toWhatsApp($notifiable): string
    {
        return "Le damos la bienvenida a MOVA, {$notifiable->name}.\n\n"
            . "Para empezar:\n"
            . "1. Agregue a su hijo/a en su perfil\n"
            . "2. Explore el marketplace\n"
            . "3. Solicite una clase\n"
            . "4. Recibirá recordatorios y reportes\n\n"
            . $this->appUrl('/dashboard');
    }

    public function toArray($notifiable): array
    {
        return [
            'type'    => 'welcome_parent',
            'message' => 'Le damos la bienvenida a MOVA. Agregue a su hijo/a y solicite su primera clase.',
        ];
    }
}
