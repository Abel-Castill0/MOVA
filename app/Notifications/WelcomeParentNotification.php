<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeParentNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
            ->subject('Bienvenido a MOVA')
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line('Gracias por unirte a MOVA. Aquí te explicamos cómo empezar:')
            ->line('**1.** Agrega a tu hijo en tu perfil.')
            ->line('**2.** Explora el marketplace y elige un profesor.')
            ->line('**3.** Solicita una clase directamente desde la oferta del profesor.')
            ->line('**4.** Recibirás recordatorios antes de cada clase y un reporte de aprendizaje después.')
            ->action('Ir al panel', url('/dashboard'))
            ->salutation('El equipo de MOVA');
    }

    public function toWhatsApp($notifiable): string
    {
        return "¡Bienvenido a MOVA, {$notifiable->name}!\n\n"
            . "Para empezar:\n"
            . "1. Agrega a tu hijo en tu perfil\n"
            . "2. Explora el marketplace\n"
            . "3. Solicita una clase\n"
            . "4. Recibirás recordatorios y reportes\n\n"
            . url('/dashboard');
    }

    public function toArray($notifiable): array
    {
        return [
            'type'    => 'welcome_parent',
            'message' => 'Bienvenido a MOVA. Agrega a tu hijo y solicita tu primera clase.',
        ];
    }
}
