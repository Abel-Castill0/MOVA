<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsAppUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeTeacherNotification extends Notification implements ShouldQueue
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
            ->subject('Le damos la bienvenida a MOVA como profesor')
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line('Colega, gracias por registrarse en MOVA como profesor. Siga estos pasos para comenzar:')
            ->line('**1.** Complete su perfil con su biografía y las materias que enseña.')
            ->line('**2.** Espere la verificación del equipo de MOVA.')
            ->line('**3.** Una vez verificado, revise las solicitudes abiertas de sus materias: acéptelas o proponga otro horario.')
            ->line('**4.** Comparta su código de profesor con las familias que ya conoce: sus solicitudes le llegarán solo a usted.')
            ->line('**5.** Aceptar una clase reserva créditos de su saldo MOVA.')
            ->line('**6.** Después de cada clase, envíe un reporte de aprendizaje.')
            ->action('Completar perfil', $this->appRoute('teacher.setup'))
            ->salutation('El equipo de MOVA');
    }

    public function toWhatsApp($notifiable): string
    {
        return "Le damos la bienvenida a MOVA como profesor, {$notifiable->name}.\n\n"
            . "Pasos para empezar:\n"
            . "1. Complete su perfil (biografía y materias)\n"
            . "2. Espere la verificación del equipo\n"
            . "3. Revise las solicitudes abiertas de sus materias: acéptelas o proponga otro horario\n"
            . "4. Comparta su código de profesor con las familias que ya conoce\n"
            . "5. Después de cada clase, envíe un reporte\n\n"
            . $this->appRoute('teacher.setup');
    }

    public function toArray($notifiable): array
    {
        return [
            'type'    => 'welcome_teacher',
            'message' => 'Le damos la bienvenida a MOVA. Complete su perfil para empezar a recibir solicitudes.',
        ];
    }
}
