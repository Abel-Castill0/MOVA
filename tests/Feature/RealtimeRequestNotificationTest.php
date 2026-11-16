<?php

namespace Tests\Feature;

use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Models\User;
use App\Notifications\ClassCancelledNotification;
use App\Notifications\ClassRequestRejectedNotification;
use App\Notifications\ClassRescheduledNotification;
use App\Notifications\CounterofferProposedNotification;
use App\Notifications\NewClassRequestNotification;
use App\Notifications\LessonReportPublishedNotification;
use App\Models\LessonReport;
use App\Notifications\ParentApprovalRequestNotification;
use App\Notifications\RechargeApprovedNotification;
use App\Notifications\RechargeReversedNotification;
use App\Notifications\LessonSettledNotification;
use App\Models\RechargeRequest;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RealtimeRequestNotificationTest extends TestCase
{
    public function test_relevant_transitions_enable_private_broadcast_notifications(): void
    {
        $request = new ClassRequest;
        $request->forceFill(['id' => 42, 'teacher_rejection_reason' => 'Contiene información del alumno']);
        $lesson = new Lesson;
        $lesson->forceFill(['start_time' => Carbon::parse('2026-11-17 01:00:00', 'UTC')]);
        $user = new User;

        $notifications = [
            new NewClassRequestNotification($request),
            new CounterofferProposedNotification($request),
            new ParentApprovalRequestNotification($request),
            new ClassRequestRejectedNotification($request),
            new ClassRescheduledNotification($lesson, 'profesor'),
            new ClassCancelledNotification($lesson),
            new RechargeApprovedNotification(new RechargeRequest),
            new RechargeReversedNotification(new RechargeRequest),
            new LessonSettledNotification($lesson),
        ];

        foreach ($notifications as $notification) {
            $this->assertContains('broadcast', $notification->via($user));
        }
    }

    public function test_broadcast_payloads_exclude_minor_names_and_free_text_reasons(): void
    {
        $request = new ClassRequest;
        $request->forceFill([
            'id' => 42,
            'teacher_rejection_reason' => 'Mi hija Valentina necesita privacidad',
        ]);
        $user = new User;

        $notifications = [
            new NewClassRequestNotification($request),
            new CounterofferProposedNotification($request),
            new ParentApprovalRequestNotification($request),
            new ClassRequestRejectedNotification($request),
        ];

        $report = new LessonReport;
        $report->forceFill(['id' => 14, 'lesson_id' => 4]);
        $report->setRelation('student', (new \App\Models\Student)->forceFill(['first_name' => 'Valentina']));
        $notifications[] = new LessonReportPublishedNotification($report);

        foreach ($notifications as $notification) {
            $payload = $notification->toBroadcast($user)->data;
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('Valentina', $json);
            $this->assertStringNotContainsString('Mi hija', $json);
            $this->assertArrayNotHasKey('student', $payload);
            $this->assertArrayNotHasKey('teacher_rejection_reason', $payload);
            $this->assertArrayHasKey('message', $payload);
        }
    }
}
