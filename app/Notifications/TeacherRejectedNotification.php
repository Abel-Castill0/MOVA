<?php

namespace App\Notifications;

use App\Models\TeacherProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeacherRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public TeacherProfile $profile) {}

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
        $mail = (new MailMessage)
            ->subject('Tu solicitud como profesor en MOVA no fue aprobada')
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line('Gracias por registrarte como profesor en MOVA.')
            ->line('Lamentamos informarte que tu perfil docente no pudo ser aprobado en esta ocasión.');

        if ($this->profile->rejection_reason) {
            $mail->line('**Motivo:** ' . $this->profile->rejection_reason);
        }

        return $mail
            ->line('Si tienes preguntas o consideras que hay un error, puedes escribirnos a: abelcastillotrabajo@gmail.com')
            ->action('Ver mi cuenta', url('/dashboard'))
            ->salutation('El equipo de MOVA');
    }

    public function toArray($notifiable): array
    {
        $message = 'Tu solicitud como profesor no fue aprobada.';
        if ($this->profile->rejection_reason) {
            $message .= ' Motivo: ' . $this->profile->rejection_reason;
        }

        return [
            'type'    => 'teacher_rejected',
            'message' => $message,
        ];
    }
}
