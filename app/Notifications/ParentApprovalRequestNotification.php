<?php

namespace App\Notifications;

use App\Models\ClassRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ParentApprovalRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ClassRequest $classRequest) {}

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
        $student = $this->classRequest->student->full_name;
        $subject = $this->classRequest->subject->name;

        return (new MailMessage)
            ->subject("{$student} ha solicitado una clase en MOVA")
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line("**{$student}** ha solicitado una clase de **{$subject}**.")
            ->line('Por favor revisa la solicitud y apruébala o recházala.')
            ->action('Revisar solicitud', url('/class-requests'))
            ->salutation('El equipo de MOVA');
    }

    public function toWhatsApp($notifiable): string
    {
        $student = $this->classRequest->student->full_name;
        $subject = $this->classRequest->subject->name;

        return "MOVA — Nueva solicitud de clase\n\n"
            . "{$student} ha solicitado una clase de {$subject}.\n"
            . "Entra a MOVA para aprobarla o rechazarla:\n"
            . url('/class-requests');
    }

    public function toArray($notifiable): array
    {
        return [
            'type'       => 'parent_approval_required',
            'request_id' => $this->classRequest->id,
            'student'    => $this->classRequest->student->full_name,
            'subject'    => $this->classRequest->subject->name,
            'message'    => $this->classRequest->student->full_name . ' solicita una clase de ' . $this->classRequest->subject->name,
        ];
    }
}
