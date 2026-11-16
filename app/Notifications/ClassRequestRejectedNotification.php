<?php

namespace App\Notifications;

use App\Models\ClassRequest;
use App\Notifications\Concerns\BuildsAppUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClassRequestRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable, BuildsAppUrls;

    public function __construct(public ClassRequest $request)
    {
        $this->afterCommit();
    }

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
        $subject = $this->request->subject?->name ?? 'la clase';

        $mail = (new MailMessage)
            ->subject('Su solicitud de clase no pudo ser aceptada en MOVA')
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line('Lamentamos informarle que el profesor no pudo aceptar su solicitud de ' . $subject . '.');

        if ($this->request->teacher_rejection_reason) {
            $mail->line('**Motivo:** ' . $this->request->teacher_rejection_reason);
        }

        return $mail
            ->line('Puede buscar otro profesor disponible en el marketplace.')
            ->action('Ver solicitudes', $this->appRoute('class-requests.index'))
            ->salutation('El equipo de MOVA');
    }

    /** El motivo libre puede contener datos personales; transmitir solo el cambio de estado. */
    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'type' => 'class_request_rejected',
            'class_request_id' => $this->request->id,
            'message' => 'Una solicitud cambió de estado.',
        ]);
    }

    public function toArray($notifiable): array
    {
        $subject = $this->request->subject?->name ?? 'la clase';
        $message = "Su solicitud de {$subject} no pudo ser aceptada por el profesor.";
        if ($this->request->teacher_rejection_reason) {
            $message .= ' Motivo: ' . $this->request->teacher_rejection_reason;
        }

        return [
            'type'             => 'class_request_rejected',
            'class_request_id' => $this->request->id,
            'message'          => $message,
        ];
    }
}
