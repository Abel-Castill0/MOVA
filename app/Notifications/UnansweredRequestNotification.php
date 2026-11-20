<?php

namespace App\Notifications;

use App\Models\ClassRequest;
use App\Notifications\Concerns\BuildsAppUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UnansweredRequestNotification extends Notification implements ShouldQueue
{
    use Queueable, BuildsAppUrls;

    /** @param  string  $kind  unanswered («sigue sin propuesta») | final (último aviso antes de la franja / del vencimiento) */
    public function __construct(public ClassRequest $classRequest, public string $kind = 'unanswered')
    {
        $this->afterCommit();
    }

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

    /**
     * Re-evaluado al ENTREGAR (va encolada): si entre el encolado y el envío alguien propuso un horario, la solicitud
     * venció, el profesor dejó de ser elegible o ya no queda oportunidad, no se envía por ningún canal.
     */
    public function shouldSend($notifiable, string $channel): bool
    {
        $request = $this->classRequest->fresh();
        $teacher = $notifiable->fresh();

        return $request !== null && $teacher !== null && $teacher->suspended_at === null
            && $request->status === 'open'
            && ! $request->opportunityHasPassed()
            && $request->isEligibleTeacherUser($teacher);
    }

    /** «para el viernes 20 de noviembre, por la tarde» o «hace más de 12 horas» (solicitudes sin día). */
    private function line(): string
    {
        $subject = $this->classRequest->subject?->name ?? 'una clase';
        $when = \App\Support\RequestSlots::describe($this->classRequest->preferred_date, $this->classRequest->preferred_slot);

        if ($this->kind === 'final') {
            return $when
                ? "Último aviso: una familia necesita una clase de {$subject} el {$when} y todavía no tiene propuesta."
                : "Último aviso: una solicitud de acompañamiento de {$subject} vence pronto y todavía no tiene propuesta.";
        }

        return $when
            ? "Una familia solicitó una clase de {$subject} para el {$when} y todavía no tiene propuesta."
            : "Una familia solicitó una clase de {$subject} hace más de 12 horas y todavía no tiene propuesta.";
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->kind === 'final' ? 'Último aviso: solicitud sin propuesta en MOVA' : 'Solicitud pendiente en MOVA')
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line('Colega, ' . lcfirst($this->line()))
            ->line('Si le viene bien, proponga la hora exacta desde su panel (hora de Perú). La familia la confirma antes de agendar.')
            ->action('Ver solicitud', $this->appRoute('teacher.requests'))
            ->salutation('El equipo de MOVA');
    }

    public function toWhatsApp($notifiable): string
    {
        return "MOVA — " . ($this->kind === 'final' ? 'Último aviso' : 'Solicitud pendiente') . "\n\n"
            . "Hola {$notifiable->name},\n"
            . $this->line() . "\n"
            . "Responda desde su panel:\n"
            . $this->appRoute('teacher.requests');
    }

    public function toArray($notifiable): array
    {
        return [
            'type'             => 'unanswered_request',
            'kind'             => $this->kind,
            'class_request_id' => $this->classRequest->id,
            'subject'          => $this->classRequest->subject?->name,
            'message'          => $this->line(),
        ];
    }
}
