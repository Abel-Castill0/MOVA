<?php

namespace App\Notifications;

use App\Models\Lesson;
use App\Notifications\Concerns\BuildsAppUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClassConfirmedNotification extends Notification implements ShouldQueue
{
    use Queueable, BuildsAppUrls;

    public function __construct(public Lesson $lesson) {}

    public function via($notifiable): array
    {
        $channels = ['database', 'broadcast'];
        if ($notifiable->email_verified_at) {
            $channels[] = 'mail';
        }
        if ($notifiable->phone_verified_at) {
            $channels[] = \App\Channels\WhatsAppChannel::class;
        }
        return $channels;
    }

    // Ver ClassReminderNotification: la sala nunca viaja en la notificación.
    // El destino depende del rol porque esta notificación llega a ambas partes.
    private function classListUrl($notifiable): string
    {
        return $notifiable->hasRole('teacher')
            ? $this->appRoute('teacher.lessons')
            : $this->appRoute('parent.lessons');
    }

    public function toMail($notifiable): MailMessage
    {
        $date = $this->lesson->start_time->format('d/m/Y \a \l\a\s H:i');

        return (new MailMessage)
            ->subject('Clase confirmada en MOVA')
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line('Su clase ha sido **confirmada** correctamente.')
            ->line('**Fecha:** ' . $date)
            ->line('**Duración:** ' . $this->lesson->duration_minutes . ' minutos')
            ->action('Ver mi clase en MOVA', $this->classListUrl($notifiable))
            ->line('Entre a la sala desde MOVA el día de la clase. Recibirá un recordatorio 10 minutos antes.')
            ->salutation('El equipo de MOVA');
    }

    public function toWhatsApp($notifiable): string
    {
        $date = $this->lesson->start_time->format('d/m/Y H:i');

        return "MOVA — Clase confirmada\n\n"
            . "Hola {$notifiable->name},\n"
            . "Fecha: {$date}\n"
            . "Duración: {$this->lesson->duration_minutes} min\n"
            . "Entre a la sala desde MOVA: " . $this->classListUrl($notifiable) . "\n\n"
            . "Recibirá un recordatorio 10 minutos antes.";
    }

    public function toArray($notifiable): array
    {
        return [
            'type'       => 'class_confirmed',
            'lesson_id'  => $this->lesson->id,
            'start_time' => $this->lesson->start_time->toISOString(),
            'message'    => 'Su clase del ' . $this->lesson->start_time->format('d/m/Y') . ' ha sido confirmada.',
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
