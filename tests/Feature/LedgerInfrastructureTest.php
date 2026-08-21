<?php

namespace Tests\Feature;

use App\Models\ClassEvent;
use App\Models\ClassRequest;
use App\Models\CreditTransaction;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Support\LedgerReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AssertsFinancialInvariants;
use Tests\TestCase;

/**
 * C-1 Entrega 1 — infraestructura financiera, sin comportamiento nuevo.
 *
 * Cubre: el scope endedBefore, las invariantes contables reutilizables, y el
 * comando de reconciliación (clasificación, códigos de salida y que sea
 * estrictamente de solo lectura).
 */
class LedgerInfrastructureTest extends TestCase
{
    use RefreshDatabase;
    use AssertsFinancialInvariants;

    // ── Scope endedBefore ────────────────────────────────────────────────────

    public function test_ended_before_uses_start_time_plus_duration_not_start_time(): void
    {
        [, , $lesson] = $this->lessonWithReservation();
        // Empieza hace 90 min y dura 60 → terminó hace 30 min.
        $lesson->update(['start_time' => now()->subMinutes(90), 'duration_minutes' => 60]);

        $this->assertTrue(
            Lesson::whereKey($lesson->id)->endedBefore(now()->subMinutes(10))->exists(),
            'Debería contar como terminada: acabó hace 30 minutos.'
        );

        $this->assertFalse(
            Lesson::whereKey($lesson->id)->endedBefore(now()->subHours(2))->exists(),
            'No debería contar como terminada hace 2 horas: aún no había acabado.'
        );
    }

    public function test_ended_before_excludes_a_long_lesson_that_started_but_has_not_finished(): void
    {
        [, , $lesson] = $this->lessonWithReservation();
        // Empezó hace 30 min pero dura 240 → sigue en curso.
        $lesson->update(['start_time' => now()->subMinutes(30), 'duration_minutes' => 240]);

        $this->assertFalse(
            Lesson::whereKey($lesson->id)->endedBefore(now())->exists(),
            'Una clase larga ya iniciada pero no terminada no debe considerarse finalizada.'
        );
    }

    public function test_ended_before_does_not_modify_data(): void
    {
        [, , $lesson] = $this->lessonWithReservation();
        $before = DB::table('classes')->where('id', $lesson->id)->first();

        Lesson::endedBefore(now()->addYear())->get();

        $this->assertEquals($before, DB::table('classes')->where('id', $lesson->id)->first());
    }

    // ── Infraestructura de columnas ──────────────────────────────────────────

    public function test_credits_settled_at_is_nullable_and_not_mass_assignable(): void
    {
        [, , $lesson] = $this->lessonWithReservation();

        $this->assertNull($lesson->credits_settled_at);

        // Mass assignment NO debe poder tocarlo: solo la futura capa de
        // settlement debe escribir este marcador financiero.
        $lesson->update(['credits_settled_at' => now()]);

        $this->assertNull($lesson->fresh()->credits_settled_at);
        $this->assertNotContains('credits_settled_at', $lesson->getFillable());
    }

    public function test_class_events_accept_a_null_actor_meaning_system(): void
    {
        // actor_id = NULL ⟺ el actor es MOVA (proceso automático).
        ClassEvent::log('lesson_auto_settled', null, null, null, 'evento de sistema');

        $event = ClassEvent::where('event_type', 'lesson_auto_settled')->first();

        $this->assertNotNull($event);
        $this->assertNull($event->actor_id);
    }

    // ── Invariantes contables ────────────────────────────────────────────────

    public function test_invariants_hold_on_a_healthy_dataset(): void
    {
        [$profile, , $lesson] = $this->lessonWithReservation();
        $this->assertSame(1, $profile->fresh()->credits_reserved);

        $this->assertFinancialInvariantsHold();
        $this->assertSettlementBehaviourNotYetIntroduced();
    }

    public function test_invariants_detect_a_closure_without_reservation(): void
    {
        [$profile, , $lesson] = $this->lessonWithReservation();
        // Se borra la reserva dejando el consumo huérfano: exactamente la
        // anomalía que el seeder producía antes de corregirse.
        CreditTransaction::where('lesson_id', $lesson->id)->where('type', 'reservation')->delete();
        $this->closeWith($profile, $lesson, 'consumption');

        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->assertLessonLedgerInvariants();
    }

    public function test_invariants_detect_consumption_and_refund_on_the_same_lesson(): void
    {
        [$profile, , $lesson] = $this->lessonWithReservation();
        $this->closeWith($profile, $lesson, 'consumption');
        $this->closeWith($profile, $lesson, 'refund');

        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->assertLessonLedgerInvariants();
    }

    public function test_invariants_detect_a_reserved_balance_the_ledger_does_not_explain(): void
    {
        [$profile] = $this->lessonWithReservation();
        $profile->update(['credits_reserved' => 99]);

        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->assertTeacherBalanceInvariants();
    }

    // ── Comando mova:reconcile-ledger ────────────────────────────────────────

    public function test_reconcile_ledger_exits_zero_and_reports_green_when_healthy(): void
    {
        $this->lessonWithReservation();

        $this->artisan('mova:reconcile-ledger')
            ->expectsOutputToContain('GREEN')
            ->assertExitCode(0);
    }

    public function test_reconcile_ledger_exits_nonzero_when_a_lesson_is_invalid(): void
    {
        [$profile, , $lesson] = $this->lessonWithReservation();
        $this->closeWith($profile, $lesson, 'consumption');
        $this->closeWith($profile, $lesson, 'refund');

        $this->artisan('mova:reconcile-ledger')
            ->expectsOutputToContain('RED')
            ->assertExitCode(1);
    }

    public function test_reconcile_ledger_exits_nonzero_when_a_balance_does_not_match(): void
    {
        [$profile] = $this->lessonWithReservation();
        $profile->update(['credits_reserved' => 42]);

        $this->artisan('mova:reconcile-ledger')->assertExitCode(1);
    }

    public function test_reconcile_ledger_never_writes(): void
    {
        [$profile, , $lesson] = $this->lessonWithReservation();
        $this->closeWith($profile, $lesson, 'consumption');
        $this->closeWith($profile, $lesson, 'refund');

        $snapshot = [
            'classes' => DB::table('classes')->orderBy('id')->get()->toJson(),
            'credit_transactions' => DB::table('credit_transactions')->orderBy('id')->get()->toJson(),
            'teacher_profiles' => DB::table('teacher_profiles')->orderBy('id')->get()->toJson(),
        ];

        $this->artisan('mova:reconcile-ledger')->assertExitCode(1);
        $this->artisan('mova:reconcile-ledger', ['--json' => true])->assertExitCode(1);

        $this->assertSame($snapshot['classes'], DB::table('classes')->orderBy('id')->get()->toJson());
        $this->assertSame($snapshot['credit_transactions'], DB::table('credit_transactions')->orderBy('id')->get()->toJson());
        $this->assertSame($snapshot['teacher_profiles'], DB::table('teacher_profiles')->orderBy('id')->get()->toJson());
    }

    public function test_reconcile_ledger_json_output_is_machine_readable(): void
    {
        [, , $lesson] = $this->lessonWithReservation();

        $this->artisan('mova:reconcile-ledger', ['--json' => true])->assertExitCode(0);

        // El comando escribe el informe por stdout; se valida la forma del
        // reporte a través del servicio compartido que lo produce.
        $report = (new LedgerReconciliation)->run();

        $this->assertTrue($report['healthy']);
        $this->assertSame(1, $report['total_lessons']);
        $this->assertSame(1, $report['counts'][LedgerReconciliation::OPEN_RESERVATION]);
        $this->assertSame($lesson->id, $report['lessons'][0]['lesson_id']);
        $this->assertNotNull(json_encode($report));
    }

    public function test_no_ledger_is_classified_apart_from_invalid(): void
    {
        [, , $lesson] = $this->lessonWithReservation();
        CreditTransaction::where('lesson_id', $lesson->id)->delete();

        $classified = (new LedgerReconciliation)->classifyLessons();

        $this->assertSame(LedgerReconciliation::NO_LEDGER, $classified[0]['classification']);
        $this->assertNotSame(LedgerReconciliation::INVALID, $classified[0]['classification']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @return array{0: TeacherProfile, 1: User, 2: Lesson} */
    private function lessonWithReservation(): array
    {
        $teacher = $this->userWithRole('teacher');
        // Saldos realistas: el profesor depositó 5 y reservó 1 al aceptar la
        // clase, así que le quedan 4 disponibles. Un fixture con reserva pero
        // sin depósito sería financieramente imposible (available derivado
        // saldría negativo) — lo detectó la propia invariante al escribir este
        // test.
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_verified' => true,
            'credits_available' => 4,
            'credits_reserved' => 1,
        ]);

        CreditTransaction::create([
            'teacher_profile_id' => $profile->id,
            'idempotency_key' => "teacher:{$profile->id}:welcome",
            'type' => 'deposit',
            'amount' => 5,
            'description' => 'Bono de bienvenida MOVA',
        ]);

        $subject = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'secundaria']);

        $parent = $this->userWithRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Alumno',
            'last_name' => 'Prueba',
            'grade_level' => 'secundaria',
        ]);
        $request = ClassRequest::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Necesita reforzar el tema.',
            'status' => 'accepted',
        ]);

        $lesson = Lesson::create([
            'teacher_profile_id' => $profile->id,
            'student_id' => $student->id,
            'class_request_id' => $request->id,
            'start_time' => now()->addDay(),
            'duration_minutes' => 60,
            'price_frozen_pen' => 20.00,
            'status' => 'scheduled',
        ]);

        CreditTransaction::create([
            'teacher_profile_id' => $profile->id,
            'lesson_id' => $lesson->id,
            'idempotency_key' => "lesson:{$lesson->id}:reservation",
            'type' => 'reservation',
            'amount' => 1,
            'description' => 'Reserva por aceptación de clase',
        ]);

        return [$profile, $teacher, $lesson];
    }

    private function closeWith(TeacherProfile $profile, Lesson $lesson, string $type): void
    {
        CreditTransaction::create([
            'teacher_profile_id' => $profile->id,
            'lesson_id' => $lesson->id,
            'idempotency_key' => "lesson:{$lesson->id}:".($type === 'refund' ? 'release' : $type),
            'type' => $type,
            'amount' => 1,
            'description' => 'Cierre de prueba',
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['password' => 'password']);
        $user->assignRole($role);

        return $user;
    }
}
