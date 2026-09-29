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
            ->line('**2.** Cree una solicitud de clase. Si un profesor le dio su código, la solicitud le llega solo a él; sin código, se publica para los profesores verificados de la materia.')
            ->line('**3.** Revise la propuesta del profesor y apruébela.')
            ->line('**4.** Recibirá recordatorios antes de cada clase y un reporte de aprendizaje al finalizar.')
            ->action('Ir al panel', $this->appRoute('dashboard'))
            ->salutation('El equipo de MOVA');
    }

    public function toWhatsApp($notifiable): string
    {
        return "Le damos la bienvenida a MOVA, {$notifiable->name}.\n\n"
            . "Para empezar:\n"
            . "1. Agregue a su hijo/a en su perfil\n"
            . "2. Cree una solicitud de clase (con o sin código de profesor)\n"
            . "3. Revise y apruebe la propuesta del profesor\n"
            . "4. Recibirá recordatorios y reportes\n\n"
            . $this->appRoute('dashboard');
    }

    public function toArray($notifiable): array
    {
        return [
            'type'    => 'welcome_parent',
            'message' => 'Le damos la bienvenida a MOVA. Agregue a su hijo/a y solicite su primera clase.',
        ];
    }
}
