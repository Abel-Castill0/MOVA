<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\User;
use App\Notifications\ClassCancelledNotification;
use App\Notifications\ClassConfirmedNotification;
use App\Notifications\ClassReminderNotification;
use App\Notifications\ClassRescheduledNotification;
use App\Support\LimaClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NotificationLimaTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_format_crosses_utc_midnight_without_mutating_original(): void
    {
        // 01:00 UTC del martes equivale a 20:00 del lunes en Perú.
        $instant = Carbon::parse('2026-11-17 01:00:00', 'UTC');

        $this->assertSame('16/11/2026 a las 20:00 (hora de Perú)', LimaClock::formatClassDate($instant));
        $this->assertSame('16/11/2026', LimaClock::formatClassDay($instant));
        $this->assertSame('2026-11-17 01:00:00', $instant->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $instant->timezoneName);
    }

    public function test_lesson_notifications_match_between_parent_teacher_mail_whatsapp_and_database_message(): void
    {
        Role::findOrCreate('parent', 'web');
        Role::findOrCreate('teacher', 'web');
        $parent = User::factory()->create(['name' => 'Padre QA']);
        $parent->assignRole('parent');
        $teacher = User::factory()->create(['name' => 'Profesor QA']);
        $teacher->assignRole('teacher');

        $lesson = new Lesson;
        $lesson->forceFill([
            'start_time' => Carbon::parse('2026-11-17 01:00:00', 'UTC'),
            'original_start_time' => Carbon::parse('2026-11-16 01:00:00', 'UTC'),
            'duration_minutes' => 60,
        ]);

        $expected = '16/11/2026 a las 20:00 (hora de Perú)';
        $original = '15/11/2026 a las 20:00 (hora de Perú)';
        $notifications = [
            new ClassConfirmedNotification($lesson),
            new ClassReminderNotification($lesson, '24h'),
            new ClassRescheduledNotification($lesson, 'el profesor'),
            new ClassCancelledNotification($lesson),
        ];

        foreach ([$parent, $teacher] as $recipient) {
            foreach ($notifications as $notification) {
                $this->assertStringContainsString($expected, $notification->toWhatsApp($recipient));
                $mail = $notification->toMail($recipient);
                $this->assertStringContainsString($expected, implode(' ', $mail->introLines));
            }

            $rescheduled = $notifications[2];
            $this->assertStringContainsString($original, implode(' ', $rescheduled->toMail($recipient)->introLines));
            $this->assertSame($expected, $rescheduled->toArray($recipient)['new_date']);
            $this->assertSame('2026-11-17T01:00:00.000000Z', $notifications[0]->toArray($recipient)['start_time']);
            $this->assertStringContainsString('16/11/2026', $notifications[0]->toArray($recipient)['message']);
            $this->assertStringContainsString('16/11/2026', $notifications[3]->toArray($recipient)['message']);
        }

        $this->assertSame('2026-11-17 01:00:00', $lesson->start_time->format('Y-m-d H:i:s'));
    }
}
