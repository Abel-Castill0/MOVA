<?php

namespace App\Console\Commands;

use App\Models\Lesson;
use Illuminate\Console\Command;

/**
 * TESTING ONLY. Backdates a lesson's start_time so that end_time is already
 * in the past, letting E2E suites (Playwright) exercise the payment
 * confirmation flow (LessonController::confirmPayment) without waiting for
 * real time to pass.
 *
 * Deliberately NOT an HTTP endpoint, controller branch, or query parameter:
 * confirmPayment() has no time-check bypass of any kind, in any environment.
 * This command only mutates a row via CLI/DB access, which Playwright already
 * has in a local dev setup and a remote attacker over HTTP never has — so the
 * payment-confirmation gate itself carries zero additional attack surface.
 * The environment guard below is defense-in-depth on top of that, not the
 * only line of defense.
 */
class TestingBackdateLesson extends Command
{
    protected $signature = 'mova:testing-backdate-lesson {lesson_id} {--minutes-ago=15 : Minutes since the lesson ended}';

    protected $description = 'TESTING ONLY: backdate a lesson so its end_time is in the past (for E2E payment-confirmation flows)';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('This command only runs in local/testing environments.');

            return self::FAILURE;
        }

        $lesson = Lesson::find($this->argument('lesson_id'));

        if (! $lesson) {
            $this->error('Lesson not found.');

            return self::FAILURE;
        }

        $minutesAgo = max(1, (int) $this->option('minutes-ago'));

        $lesson->update([
            'start_time' => now()->subMinutes($lesson->duration_minutes + $minutesAgo),
        ]);

        $this->info("Lesson #{$lesson->id} backdated — end_time is now {$minutesAgo} minute(s) in the past.");

        return self::SUCCESS;
    }
}
