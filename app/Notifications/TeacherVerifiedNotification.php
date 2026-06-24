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
        $channels = ['database'];
        if ($notifiable->email_verified_at) {
            $channels[] = 'mail';
        }
        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Tu perfil docente fue verificado en MOVA')
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line('Tu perfil de profesor ha sido **verificado** por nuestro equipo.')
            ->line('Ya puedes recibir solicitudes de clase y comenzar a enseñar en MOVA.')
            ->action('Ver mi perfil', url('/'))
            ->salutation('El equipo de MOVA');
    }

    public function toArray($notifiable): array
    {
        return [
            'type'    => 'teacher_verified',
            'message' => 'Tu perfil de profesor ha sido verificado. Ya puedes recibir solicitudes de clase.',
        ];
    }
}
