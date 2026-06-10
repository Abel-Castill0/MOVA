<?php

namespace App\Notifications;

use App\Models\ClassRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewClassRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ClassRequest $classRequest) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Nueva solicitud de clase - ClassMate')
            ->line('Has recibido una nueva solicitud de clase.')
            ->line('Asignatura: ' . $this->classRequest->subject->name)
            ->line('Estudiante: ' . $this->classRequest->student->full_name)
            ->action('Ver solicitud', url('/teacher/requests'));
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'new_class_request',
            'request_id' => $this->classRequest->id,
            'subject' => $this->classRequest->subject->name,
            'student' => $this->classRequest->student->full_name,
        ];
    }
}
