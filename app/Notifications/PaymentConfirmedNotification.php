<?php

namespace App\Notifications;

use App\Models\Lesson;
use App\Notifications\Concerns\BuildsAppUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentConfirmedNotification extends Notification implements ShouldQueue
{
    use Queueable, BuildsAppUrls;

    public function __construct(public Lesson $lesson) {}

    public function via($notifiable): array
    {
        $channels = ['database', 'broadcast'];
        if ($notifiable->email_verified_at) {
            $channels[] = 'mail';
        }
        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        $subject = $this->lesson->classRequest?->subject?->name ?? 'una clase';
        $student = $this->lesson->student?->first_name ?? 'el alumno';

        return (new MailMessage)
            ->subject('Pago confirmado en MOVA')
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line("El padre/tutor de {$student} confirmó el pago de la clase de {$subject}.")
            ->action('Ver clase y subir reporte', $this->appRoute('teacher.lessons'))
            ->salutation('El equipo de MOVA');
    }

    public function toArray($notifiable): array
    {
        $subject = $this->lesson->classRequest?->subject?->name ?? 'Clase';

        return [
            'type'      => 'payment_confirmed',
            'lesson_id' => $this->lesson->id,
            'subject'   => $subject,
            'message'   => "El padre confirmó el pago de la clase de {$subject}.",
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
