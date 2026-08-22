<?php

namespace App\Notifications;

use App\Models\ClassRequest;
use App\Notifications\Concerns\BuildsAppUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ParentApprovalRequestNotification extends Notification implements ShouldQueue
{
    use Queueable, BuildsAppUrls;

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
            ->line('Por favor, revise la solicitud y apruébela o rechácela.')
            ->action('Revisar solicitud', $this->appRoute('class-requests.index'))
            ->salutation('El equipo de MOVA');
    }

    public function toWhatsApp($notifiable): string
    {
        $student = $this->classRequest->student->full_name;
        $subject = $this->classRequest->subject->name;

        return "MOVA — Nueva solicitud de clase\n\n"
            . "{$student} ha solicitado una clase de {$subject}.\n"
            . "Ingrese a MOVA para aprobarla o rechazarla:\n"
            . $this->appRoute('class-requests.index');
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
