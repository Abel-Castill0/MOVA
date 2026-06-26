<?php

namespace App\Notifications;

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
    use Queueable;

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $isTeacher = $notifiable->hasRole('teacher');

        $mail = (new MailMessage)
            ->subject('Bienvenido a MOVA — Tu cuenta está lista')
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line('Tu correo fue verificado. Ya puedes usar MOVA al 100%.');

        if ($isTeacher) {
            $mail->line('**Próximos pasos:**')
                 ->line('1. Completa tu perfil con bio y tarifa.')
                 ->line('2. Espera verificación del equipo MOVA.')
                 ->line('3. Crea tus ofertas de clase y responde solicitudes.')
                 ->action('Completar perfil', url('/teacher/setup'));
        } else {
            $mail->line('**Próximos pasos:**')
                 ->line('1. Agrega a tu hijo en tu perfil.')
                 ->line('2. Explora el marketplace y elige un profesor.')
                 ->line('3. Solicita una clase — recibirás recordatorios y reportes.')
                 ->action('Ir al panel', url('/dashboard'));
        }

        return $mail->salutation('El equipo de MOVA');
    }
}
