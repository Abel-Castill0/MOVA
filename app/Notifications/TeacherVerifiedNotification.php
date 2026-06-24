<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeacherVerifiedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('✅ Perfil verificado – MOVA')
            ->greeting('¡Felicitaciones, ' . $notifiable->name . '!')
            ->line('Tu perfil de profesor ha sido **verificado** por nuestro equipo.')
            ->line('Ya puedes recibir solicitudes de clase y comenzar a enseñar en MOVA.')
            ->action('Ver mi perfil', url('/'))
            ->salutation('El equipo de MOVA');
    }

    public function toArray($notifiable): array
    {
        return [
            'type'    => 'teacher_verified',
            'message' => 'Tu perfil de profesor ha sido verificado. ¡Bienvenido a MOVA!',
        ];
    }
}
