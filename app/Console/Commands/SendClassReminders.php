<?php

namespace App\Console\Commands;

use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Notifications\ClassReminderNotification;
use App\Notifications\PendingReportReminderNotification;
use App\Notifications\UnansweredRequestNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendClassReminders extends Command
{
    protected $signature = 'classmate:send-reminders';
    protected $description = 'Send class reminders (24h, 2h, 10m) and pending report alerts';

    public function handle(): void
    {
        $this->send24hReminders();
        $this->send2hReminders();
        $this->send10mReminders();
        $this->sendPendingReportAlerts();
        $this->sendUnansweredRequestAlerts();
    }

    private function notifyBoth(Lesson $lesson, $notification): void
    {
        if ($lesson->teacherProfile?->user) {
            $lesson->teacherProfile->user->notify($notification);
        }
        if ($lesson->student?->parent) {
            $lesson->student->parent->notify(clone $notification);
        }
    }

    private function send24hReminders(): void
    {
        $lessons = Lesson::where('status', 'scheduled')
            ->whereNull('reminder_24h_sent_at')
            ->whereBetween('start_time', [now()->addHours(23), now()->addHours(25)])
            ->with(['teacherProfile.user', 'student.parent'])
            ->get();

        foreach ($lessons as $lesson) {
            $notif = new ClassReminderNotification($lesson, '24h');
            $this->notifyBoth($lesson, $notif);
            $lesson->update(['reminder_24h_sent_at' => now()]);
        }

        $this->info("24h reminders: {$lessons->count()}");
    }

    private function send2hReminders(): void
    {
        $lessons = Lesson::where('status', 'scheduled')
            ->whereNull('reminder_2h_sent_at')
            ->whereBetween('start_time', [now()->addMinutes(90), now()->addMinutes(150)])
            ->with(['teacherProfile.user', 'student.parent'])
            ->get();

        foreach ($lessons as $lesson) {
            $notif = new ClassReminderNotification($lesson, '2h');
            $this->notifyBoth($lesson, $notif);
            $lesson->update(['reminder_2h_sent_at' => now()]);
        }

        $this->info("2h reminders: {$lessons->count()}");
    }

    private function send10mReminders(): void
    {
        $lessons = Lesson::where('status', 'scheduled')
            ->where('reminder_sent', false)
            ->whereBetween('start_time', [now(), now()->addMinutes(10)])
            ->with(['teacherProfile.user', 'student.parent'])
            ->get();

        foreach ($lessons as $lesson) {
            $notif = new ClassReminderNotification($lesson, '10m');
            $this->notifyBoth($lesson, $notif);
            $lesson->update(['reminder_sent' => true]);
        }

        $this->info("10m reminders: {$lessons->count()}");
    }

    private function sendPendingReportAlerts(): void
    {
        // C-1 (A-1, Fase 3B §11): 'status=completed' + sin reporte era
        // imposible antes de C-1 (completed solo llegaba tras crear el
        // reporte) — este aviso nunca se disparó. El estado real de "debe un
        // reporte" es 'paid' dentro de la ventana de gracia; fuera de ella el
        // scheduler ya liquidó la clase sin reporte, y avisar llegaría tarde.
        // Misma condición que DashboardController — ver
        // Lesson::scopeAwaitingReportWithinGrace() para no triplicarla.
        $lessons = Lesson::awaitingReportWithinGrace()
            ->whereNull('report_reminder_sent_at')
            ->with(['teacherProfile.user'])
            ->get();

        $sent = 0;

        foreach ($lessons as $lesson) {
            // report_reminder_sent_at se escribe en la MISMA transacción que
            // marca el envío — dos pasadas concurrentes del scheduler no
            // deben poder duplicar el aviso (antes el update() iba después
            // del notify(), sin transacción, dejando una ventana de carrera).
            $sentNow = DB::transaction(function () use ($lesson) {
                $locked = Lesson::whereKey($lesson->id)->lockForUpdate()->firstOrFail();

                // Recheck status bajo lock, no solo report_reminder_sent_at:
                // entre el SELECT de arriba y este lock, un admin pudo forzar
                // el cierre de esta misma lección (force-complete/refund) o
                // el padre pudo reseñarla — 'paid' ya no aplica y avisar
                // "te falta el reporte" sería ruido sobre una clase que ya
                // no está pendiente por esa razón.
                if ($locked->report_reminder_sent_at !== null || $locked->status !== 'paid') {
                    return false;
                }

                $locked->update(['report_reminder_sent_at' => now()]);

                return true;
            });

            if ($sentNow && $lesson->teacherProfile?->user) {
                $lesson->teacherProfile->user->notify(new PendingReportReminderNotification($lesson));
                $sent++;
            }
        }

        $this->info("Pending report alerts: {$sent}");
    }

    private function sendUnansweredRequestAlerts(): void
    {
        // Open requests older than 12 hours with no reminder sent yet
        $requests = ClassRequest::where('status', 'open')
            ->whereNull('request_reminder_sent_at')
            ->where('created_at', '<=', now()->subHours(12))
            ->with(['classOffer.teacherProfile.user', 'subject'])
            ->get();

        foreach ($requests as $classRequest) {
            $teacher = $classRequest->classOffer?->teacherProfile?->user;
            if ($teacher) {
                $teacher->notify(new UnansweredRequestNotification($classRequest));
            }
            $classRequest->update(['request_reminder_sent_at' => now()]);
        }

        $this->info("Unanswered request alerts: {$requests->count()}");
    }
}
