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
            ->subject('Bienvenido a MOVA como profesor')
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line('Gracias por registrarte en MOVA como profesor. Sigue estos pasos para empezar:')
            ->line('**1.** Completa tu perfil con tu biografía y tarifa por hora.')
            ->line('**2.** Agrega las materias que enseñas.')
            ->line('**3.** Espera la verificación del equipo de MOVA.')
            ->line('**4.** Una vez verificado, crea tus ofertas de clase.')
            ->line('**5.** Responde las solicitudes de los padres.')
            ->line('**6.** Después de cada clase, envía un reporte de aprendizaje.')
            ->action('Completar perfil', url('/teacher/setup'))
            ->salutation('El equipo de MOVA');
    }

    public function toWhatsApp($notifiable): string
    {
        return "¡Bienvenido a MOVA como profesor, {$notifiable->name}!\n\n"
            . "Pasos para empezar:\n"
            . "1. Completa tu perfil (bio y tarifa)\n"
            . "2. Agrega tus materias\n"
            . "3. Espera verificación del equipo\n"
            . "4. Crea tus ofertas de clase\n"
            . "5. Responde solicitudes y envía reportes\n\n"
            . url('/teacher/setup');
    }

    public function toArray($notifiable): array
    {
        return [
            'type'    => 'welcome_teacher',
            'message' => 'Bienvenido a MOVA. Completa tu perfil para empezar a recibir solicitudes.',
        ];
    }
}
