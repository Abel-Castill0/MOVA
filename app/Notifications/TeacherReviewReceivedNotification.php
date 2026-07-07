<?php

namespace App\Notifications;

use App\Models\TeacherReview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeacherReviewReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly TeacherReview $review) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->email_verified_at) {
            $channels[] = 'mail';
        }
        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $rating  = $this->review->rating;
        $stars   = str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
        $subject = $this->review->lesson->classRequest?->subject?->name ?? 'una clase';

        $mail = (new MailMessage)
            ->subject('Nueva reseña recibida en MOVA')
            ->greeting('Hola, ' . $notifiable->name . '.')
            ->line("Colega, recibió una nueva reseña de " . $subject . ".")
            ->line("Calificación: {$stars} ({$rating}/5)");

        if ($this->review->comment) {
            $mail->line('"' . $this->review->comment . '"');
        }

        return $mail->line('Las reseñas son anónimas para proteger la privacidad del alumno.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'    => 'teacher_review_received',
            'rating'  => $this->review->rating,
            'subject' => $this->review->lesson->classRequest?->subject?->name ?? 'Clase',
            'message' => 'Recibió una nueva reseña de ' . $this->review->rating . '/5',
        ];
    }
}
