<?php

namespace App\Console\Commands;

use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Notifications\ClassReminderNotification;
use App\Notifications\PendingReportReminderNotification;
use App\Notifications\UnansweredRequestNotification;
use Illuminate\Console\Command;

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
        // Classes completed 2+ hours ago with no lesson_report and no alert sent yet
        $lessons = Lesson::where('status', 'completed')
            ->whereNull('report_reminder_sent_at')
            ->where('updated_at', '<=', now()->subHours(2))
            ->whereDoesntHave('lessonReport')
            ->with(['teacherProfile.user'])
            ->get();

        foreach ($lessons as $lesson) {
            if ($lesson->teacherProfile?->user) {
                $lesson->teacherProfile->user->notify(new PendingReportReminderNotification($lesson));
            }
            $lesson->update(['report_reminder_sent_at' => now()]);
        }

        $this->info("Pending report alerts: {$lessons->count()}");
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
