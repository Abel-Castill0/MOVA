<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsAppUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent via mail only after email verification.
 * The in-app welcome is sent at registration via WelcomeParentNotification / WelcomeTeacherNotification.
 */
class WelcomeEmailNotification extends Notification implements ShouldQueue
{
    use Queueable, BuildsAppUrls;

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $isTeacher = $notifiable->hasRole('teacher');

        $mail = (new MailMessage)
            ->subject('MOVA — Su cuenta está lista')
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line('Su correo fue verificado. Ya puede usar MOVA con todas sus funcionalidades.');

        if ($isTeacher) {
            $mail->line('**Próximos pasos:**')
                 ->line('1. Complete su perfil con biografía y tarifa.')
                 ->line('2. Espere la verificación del equipo MOVA.')
                 ->line('3. Revise y responda las solicitudes de clase disponibles.')
                 ->action('Completar perfil', $this->appRoute('teacher.setup'));
        } else {
            $mail->line('**Próximos pasos:**')
                 ->line('1. Agregue a su hijo/a en su perfil.')
                 ->line('2. Explore el marketplace y elija un profesor.')
                 ->line('3. Solicite una clase — recibirá recordatorios y reportes.')
                 ->action('Ir al panel', $this->appRoute('dashboard'));
        }

        return $mail->salutation('El equipo de MOVA');
    }
}
