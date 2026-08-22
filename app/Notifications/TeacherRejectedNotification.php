<?php

namespace App\Notifications;

use App\Models\TeacherProfile;
use App\Notifications\Concerns\BuildsAppUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeacherRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable, BuildsAppUrls;

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
            ->subject('Su solicitud como profesor en MOVA no fue aprobada')
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line('Gracias por registrarse como profesor en MOVA.')
            ->line('Lamentamos informarle que su perfil docente no pudo ser aprobado en esta ocasión.');

        if ($this->profile->rejection_reason) {
            $mail->line('**Motivo:** ' . $this->profile->rejection_reason);
        }

        return $mail
            ->line('Si tiene preguntas o considera que hay un error, puede escribirnos a: m0v4class@gmail.com')
            ->action('Ver mi cuenta', $this->appRoute('dashboard'))
            ->salutation('El equipo de MOVA');
    }

    public function toArray($notifiable): array
    {
        $message = 'Su solicitud como profesor no fue aprobada.';
        if ($this->profile->rejection_reason) {
            $message .= ' Motivo: ' . $this->profile->rejection_reason;
        }

        return [
            'type'    => 'teacher_rejected',
            'message' => $message,
        ];
    }
}
