<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeTeacherNotification extends Notification implements ShouldQueue
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
            ->subject('Le damos la bienvenida a MOVA como profesor')
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line('Colega, gracias por registrarse en MOVA como profesor. Siga estos pasos para comenzar:')
            ->line('**1.** Complete su perfil con biografía y tarifa por hora.')
            ->line('**2.** Agregue las materias que enseña.')
            ->line('**3.** Espere la verificación del equipo de MOVA.')
            ->line('**4.** Una vez verificado, cree sus ofertas de clase.')
            ->line('**5.** Responda las solicitudes de las familias.')
            ->line('**6.** Después de cada clase, envíe un reporte de aprendizaje.')
            ->action('Completar perfil', url('/teacher/setup'))
            ->salutation('El equipo de MOVA');
    }

    public function toWhatsApp($notifiable): string
    {
        return "Le damos la bienvenida a MOVA como profesor, {$notifiable->name}.\n\n"
            . "Pasos para empezar:\n"
            . "1. Complete su perfil (biografía y tarifa)\n"
            . "2. Agregue sus materias\n"
            . "3. Espere la verificación del equipo\n"
            . "4. Cree sus ofertas de clase\n"
            . "5. Responda solicitudes y envíe reportes\n\n"
            . url('/teacher/setup');
    }

    public function toArray($notifiable): array
    {
        return [
            'type'    => 'welcome_teacher',
            'message' => 'Le damos la bienvenida a MOVA. Complete su perfil para empezar a recibir solicitudes.',
        ];
    }
}
