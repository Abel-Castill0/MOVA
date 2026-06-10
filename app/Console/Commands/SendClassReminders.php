<?php

namespace App\Console\Commands;

use App\Models\Lesson;
use App\Notifications\ClassReminderNotification;
use Illuminate\Console\Command;

class SendClassReminders extends Command
{
    protected $signature = 'classmate:send-reminders';
    protected $description = 'Send reminders for classes starting within 10 minutes';

    public function handle(): void
    {
        $lessons = Lesson::where('status', 'scheduled')
            ->where('reminder_sent', false)
            ->whereBetween('start_time', [now(), now()->addMinutes(10)])
            ->with(['teacherProfile.user', 'student.parent'])
            ->get();

        foreach ($lessons as $lesson) {
            $lesson->teacherProfile->user->notify(new ClassReminderNotification($lesson));
            $lesson->student->parent->notify(new ClassReminderNotification($lesson));
            $lesson->update(['reminder_sent' => true]);
        }

        $this->info("Sent reminders for {$lessons->count()} classes.");
    }
}
