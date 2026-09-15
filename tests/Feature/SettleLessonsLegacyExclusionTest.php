<?php

namespace Tests\Feature;

use App\Models\ClassRequest;
use App\Models\CreditTransaction;
use App\Models\Lesson;
use App\Models\LessonReport;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Support\LedgerReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * AZ-3F — el snapshot real rescatado de Railway tiene 1 lección
 * LEGACY_PRE_LEDGER 'scheduled' vencida que `mova:settle-lessons` escalaba a
 * needs_admin_review pese a no tener (ni poder tener) ningún asiento de
 * ledger: credit_transactions no existía todavía cuando se creó. Escalar o
 * liquidar una lección así fabricaría un evento financiero sobre una clase
 * que el propio reconciler ya sabe tratar aparte (LEGACY_PRE_LEDGER, no
 * NO_LEDGER). El corte es por fecha (LedgerReconciliation::LEDGER_EPOCH),
 * igual que en el reconciler — nunca duplicado como literal aquí.
 */
class SettleLessonsLegacyExclusionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        config([
            'credits.settlement_grace_days' => 7,
            'credits.unconfirmed_days' => 7,
        ]);
    }

    public function test_legacy_scheduled_lesson_past_due_is_not_a_candidate(): void
    {
        [, , $lesson] = $this->lessonWithoutReservation('scheduled', now()->subDays(9));
        $this->backdateBeforeLedgerEpoch($lesson);

        $report = $this->runDryRunJson();

        $this->assertSame(0, $report['would_review']);
        $this->assertSame('scheduled', $lesson->fresh()->status, 'no debe cambiar su status.');
    }

    public function test_legacy_paid_lesson_past_grace_is_not_a_candidate(): void
    {
        [, , $lesson] = $this->lessonWithoutReservation('paid', now()->subDays(8));
        $this->backdateBeforeLedgerEpoch($lesson);

        $report = $this->runDryRunJson();

        $this->assertSame(0, $report['would_consume']);
        $this->assertSame(0, $report['would_escalate_missing_report']);
        $this->assertSame('paid', $lesson->fresh()->status);
        $this->assertNull($lesson->fresh()->credits_settled_at);
    }

    public function test_legacy_pending_parent_confirmation_lesson_is_not_a_candidate(): void
    {
        [, , $lesson] = $this->lessonWithoutReservation('pending_parent_confirmation', now()->subDays(8));
        $this->backdateBeforeLedgerEpoch($lesson);

        $report = $this->runDryRunJson();

        $this->assertSame(0, $report['would_consume']);
        $this->assertSame(0, $report['would_escalate_missing_report']);
        $this->assertSame('pending_parent_confirmation', $lesson->fresh()->status);
    }

    /**
     * expectsOutputToContain() no es fiable con el JSON de este comando: el
     * output mockeado de PendingCommand envuelve líneas largas
     * ("would_escalate_missing_report") de forma que rompe la subcadena a
     * mitad de línea (verificado imprimiendo el output real: el valor SÍ es
     * el esperado). Se decodifica el JSON real en su lugar, mismo patrón que
     * ya usa SchedulerConfigurationTest para mova:health-check.
     *
     * @return array<string, mixed>
     */
    private function runDryRunJson(): array
    {
        $bufferedOutput = new \Symfony\Component\Console\Output\BufferedOutput();
        $outputStyle = new \Illuminate\Console\OutputStyle(
            new \Symfony\Component\Console\Input\ArrayInput(['--dry-run' => true, '--json' => true]),
            $bufferedOutput
        );
        $exitCode = \Illuminate\Support\Facades\Artisan::call('mova:settle-lessons', ['--dry-run' => true, '--json' => true], $outputStyle);
        $this->assertSame(0, $exitCode);

        return json_decode($bufferedOutput->fetch(), true);
    }

    public function test_post_ledger_scheduled_lesson_past_due_is_still_a_candidate(): void
    {
        // Sin backdate: created_at es "now" por defecto, posterior al epoch.
        [, , $lesson] = $this->lessonWithoutReservation('scheduled', now()->subDays(9));

        $report = $this->runDryRunJson();

        $this->assertSame(1, $report['would_review']);
    }

    public function test_post_ledger_paid_lesson_with_reservation_is_still_a_candidate(): void
    {
        [, $profile, $lesson] = $this->lessonWithReservation('paid', now()->subDays(8));
        $this->reportFor($lesson);

        $report = $this->runDryRunJson();

        $this->assertSame(1, $report['would_consume']);

        // Nada se toca en dry-run.
        $this->assertSame('paid', $lesson->fresh()->status);
        $this->assertSame(1, $profile->fresh()->credits_reserved);
    }

    public function test_reconciler_still_classifies_the_legacy_lesson_as_legacy_pre_ledger(): void
    {
        [, , $lesson] = $this->lessonWithoutReservation('scheduled', now()->subDays(9));
        $this->backdateBeforeLedgerEpoch($lesson);

        $classified = collect((new LedgerReconciliation())->classifyLessons())
            ->firstWhere('lesson_id', $lesson->id);

        $this->assertSame(LedgerReconciliation::LEGACY_PRE_LEDGER, $classified['classification']);

        // El fix de settle-lessons no debe afectar en nada al reconciler.
        $this->artisan('mova:settle-lessons --dry-run --json')->assertExitCode(0);

        $classifiedAfter = collect((new LedgerReconciliation())->classifyLessons())
            ->firstWhere('lesson_id', $lesson->id);
        $this->assertSame(LedgerReconciliation::LEGACY_PRE_LEDGER, $classifiedAfter['classification']);
    }

    /** @return array{0: User, 1: TeacherProfile, 2: Lesson} */
    private function lessonWithoutReservation(string $status, \Illuminate\Support\Carbon $startTime): array
    {
        [$teacher, $profile, $student] = $this->fixtures();

        $lesson = Lesson::create([
            'teacher_profile_id' => $profile->id,
            'student_id' => $student->id,
            'class_request_id' => $this->classRequestFor($student)->id,
            'start_time' => $startTime,
            'duration_minutes' => 60,
            'status' => $status,
        ]);

        return [$teacher, $profile, $lesson];
    }

    /** @return array{0: User, 1: TeacherProfile, 2: Lesson} */
    private function lessonWithReservation(string $status, \Illuminate\Support\Carbon $startTime): array
    {
        [$teacher, $profile, $lesson] = $this->lessonWithoutReservation($status, $startTime);
        $profile->update(['credits_reserved' => 1]);

        CreditTransaction::create([
            'teacher_profile_id' => $profile->id,
            'lesson_id' => $lesson->id,
            'idempotency_key' => "lesson:{$lesson->id}:reservation",
            'type' => 'reservation',
            'amount' => 1,
            'description' => 'Reserva por aceptación de clase',
        ]);

        return [$teacher, $profile, $lesson];
    }

    private function reportFor(Lesson $lesson): LessonReport
    {
        return LessonReport::create([
            'lesson_id' => $lesson->id,
            'teacher_profile_id' => $lesson->teacher_profile_id,
            'student_id' => $lesson->student_id,
            'topic_covered' => 'Tema de prueba',
            'student_performance' => 'Buen desempeño',
            'sent_to_parent_at' => now(),
        ]);
    }

    private function classRequestFor(Student $student): ClassRequest
    {
        $subject = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'secundaria']);

        return ClassRequest::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Necesita reforzar el tema.',
            'status' => 'accepted',
        ]);
    }

    /** @return array{0: User, 1: TeacherProfile, 2: Student} */
    private function fixtures(): array
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_verified' => true,
            'credits_available' => 0,
            'credits_reserved' => 0,
        ]);

        $parent = User::factory()->create();
        $parent->assignRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Alumno',
            'last_name' => 'Prueba',
            'grade_level' => 'secundaria',
        ]);

        return [$teacher, $profile, $student];
    }

    /**
     * Retrocede `created_at` a antes de LedgerReconciliation::LEDGER_EPOCH,
     * escribiendo directamente por query builder (igual que lee tanto
     * LedgerReconciliation como el fix de SettleLessons).
     */
    private function backdateBeforeLedgerEpoch(Lesson $lesson): void
    {
        DB::table('classes')->where('id', $lesson->id)->update([
            'created_at' => '2026-06-24 08:15:09',
        ]);
    }
}
