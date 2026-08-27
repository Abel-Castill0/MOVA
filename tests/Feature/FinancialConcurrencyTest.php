<?php

namespace Tests\Feature;

use App\Models\ClassRequest;
use App\Models\CreditTransaction;
use App\Models\Lesson;
use App\Models\LessonReport;
use App\Models\RechargeRequest;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Policies\RechargeRequestPolicy;
use App\Services\LessonSettlementService;
use App\Support\LedgerReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * GAP-03 / F-17 — Operaciones financieras bajo repetición y competencia.
 *
 * LIMITACIÓN DECLARADA, igual que en LessonSettlementScenariosTest: la suite
 * corre sobre SQLite en memoria, una conexión, un proceso. Esto prueba
 * SERIALIZACIÓN (dos intentos consecutivos sobre el mismo recurso) y la
 * idempotencia a nivel de BD, NO dos procesos compitiendo por un lock del
 * motor.
 *
 * Lo que sí se demuestra aquí es que la garantía NO depende de la
 * temporización: el UNIQUE de credit_transactions.idempotency_key lo evalúa el
 * motor de base de datos, así que un segundo intento no puede duplicar el
 * asiento aunque llegue en cualquier orden. Se verifica además, tras cada
 * escenario, que LedgerReconciliation siga considerando sano el ledger — que
 * es la comprobación que de verdad importa.
 */
class FinancialConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        Notification::fake();
        Event::fake([\App\Events\ClassConfirmed::class]);
        config([
            'credits.recharges.enabled'             => true,
            'credits.recharges.payment_destination' => 'TEST-DESTINATION',
        ]);
    }

    // ── Aceptar la misma solicitud dos veces ─────────────────────────────

    public function test_the_same_class_request_cannot_be_accepted_twice(): void
    {
        $subject = $this->subject();
        [$teacherA, $profileA] = $this->teacher($subject, credits: 10);
        [$teacherB, $profileB] = $this->teacher($subject, credits: 10);
        $request = $this->openRequest($subject);

        $payload = [
            'class_request_id' => $request->id,
            'start_time'       => now()->addDays(2)->setTime(15, 0)->toIso8601String(),
            'duration_minutes' => 60,
        ];

        $this->actingAs($teacherA)->post(route('lessons.store'), $payload);
        // El segundo profesor llega tarde: la solicitud ya no está 'open'.
        $this->actingAs($teacherB)->post(route('lessons.store'), $payload)->assertForbidden();

        $this->assertDatabaseCount('classes', 1);
        $this->assertSame(9, $profileA->fresh()->credits_available);
        $this->assertSame(1, $profileA->fresh()->credits_reserved);
        // El que perdió la carrera no debe haber gastado nada.
        $this->assertSame(10, $profileB->fresh()->credits_available);
        $this->assertSame(0, $profileB->fresh()->credits_reserved);

        $this->assertLedgerHealthy();
    }

    // ── Liquidación repetida ─────────────────────────────────────────────

    public function test_repeated_settlement_consumes_the_credit_only_once(): void
    {
        [$profile, $lesson] = $this->settleableLesson();
        $settlement = app(LessonSettlementService::class);

        $settlement->consume($lesson, null, 'Primera pasada', 'auto_settled', false);
        $settlement->consume($lesson->fresh(), null, 'Segunda pasada', 'auto_settled', false);

        $this->assertSame(
            1,
            CreditTransaction::where('idempotency_key', "lesson:{$lesson->id}:consumption")->count(),
            'El UNIQUE de idempotency_key debe impedir el segundo asiento.'
        );
        $this->assertSame(0, $profile->fresh()->credits_reserved);
        $this->assertLedgerHealthy();
    }

    public function test_running_the_settlement_command_twice_does_not_double_consume(): void
    {
        [$profile, $lesson] = $this->settleableLesson();

        $this->artisan('mova:settle-lessons')->assertExitCode(0);
        $this->artisan('mova:settle-lessons')->assertExitCode(0);

        $this->assertSame(1, CreditTransaction::where('lesson_id', $lesson->id)->where('type', 'consumption')->count());
        $this->assertSame(0, $profile->fresh()->credits_reserved);
        $this->assertLedgerHealthy();
    }

    // ── Refund repetido ──────────────────────────────────────────────────

    public function test_repeated_refund_releases_the_credit_only_once(): void
    {
        [$profile, $lesson] = $this->settleableLesson();
        $settlement = app(LessonSettlementService::class);

        $settlement->refund($lesson, null, 'Primera devolución', 'admin_refunded', false);
        $settlement->refund($lesson->fresh(), null, 'Segunda devolución', 'admin_refunded', false);

        $this->assertSame(1, CreditTransaction::where('idempotency_key', "lesson:{$lesson->id}:release")->count());
        $this->assertSame(0, $profile->fresh()->credits_reserved);
        $this->assertSame(1, $profile->fresh()->credits_available, 'El crédito se devuelve una sola vez.');
        $this->assertLedgerHealthy();
    }

    public function test_a_lesson_cannot_be_both_consumed_and_refunded(): void
    {
        [$profile, $lesson] = $this->settleableLesson();
        $settlement = app(LessonSettlementService::class);

        $settlement->consume($lesson, null, 'Consumo', 'auto_settled', false);
        // Ya liquidada: el refund posterior no debe fabricar un crédito.
        $settlement->refund($lesson->fresh(), null, 'Intento de devolución', 'admin_refunded', false);

        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertSame(0, $profile->fresh()->credits_reserved);
        $this->assertSame(0, CreditTransaction::where('idempotency_key', "lesson:{$lesson->id}:release")->count());
        $this->assertLedgerHealthy();
    }

    // ── Aprobación de recarga repetida ───────────────────────────────────

    public function test_repeated_recharge_approval_deposits_once(): void
    {
        $subject = $this->subject();
        [, $profile] = $this->teacher($subject);
        $adminA = $this->userWithRole('admin');
        $adminB = $this->userWithRole('admin');
        $recharge = $this->recharge($profile);

        $this->actingAs($adminA)->post(route('admin.recharges.approve', $recharge));
        $this->actingAs($adminB)->post(route('admin.recharges.approve', $recharge));

        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "recharge:{$recharge->id}:deposit")->count());
        $this->assertLedgerHealthy();
    }

    public function test_an_approved_recharge_cannot_be_rejected_afterwards(): void
    {
        $subject = $this->subject();
        [, $profile] = $this->teacher($subject);
        $admin = $this->userWithRole('admin');
        $recharge = $this->recharge($profile);

        $this->actingAs($admin)->post(route('admin.recharges.approve', $recharge));
        $this->actingAs($admin)->post(route('admin.recharges.reject', $recharge), ['reason' => 'me arrepentí']);

        $this->assertSame('approved', $recharge->fresh()->status, 'Una recarga aprobada no debe poder rechazarse.');
        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertLedgerHealthy();
    }

    // ── F-17: la Policy expresa su intención sin depender de before() ────

    public function test_only_admins_can_approve_reject_or_reverse_a_recharge(): void
    {
        $subject = $this->subject();
        [$teacher, $profile] = $this->teacher($subject);
        $parent = $this->userWithRole('parent');
        $admin  = $this->userWithRole('admin');
        $recharge = $this->recharge($profile);

        $policy = new RechargeRequestPolicy();

        foreach (['approve', 'reject', 'reverse'] as $ability) {
            $this->assertTrue($policy->{$ability}($admin, $recharge), "admin debería poder {$ability}.");
            $this->assertFalse($policy->{$ability}($teacher, $recharge), "teacher NO debería poder {$ability}.");
            $this->assertFalse($policy->{$ability}($parent, $recharge), "parent NO debería poder {$ability}.");
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function assertLedgerHealthy(): void
    {
        $report = app(LedgerReconciliation::class)->run();

        $this->assertTrue(
            $report['healthy'] ?? false,
            'El ledger dejó de ser consistente: '.json_encode($report['needs_attention'] ?? $report)
        );
    }

    private function subject(): Subject
    {
        return Subject::create([
            'name'  => 'Materia '.fake()->unique()->numerify('####'),
            'level' => 'secundaria',
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['password' => 'password']);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @return array{0: User, 1: TeacherProfile}
     *
     * Los créditos iniciales se siembran con un asiento `deposit` real, no
     * escribiendo el saldo a mano. Es necesario para que LedgerReconciliation
     * pueda derivar el saldo desde el ledger: un saldo sin asiento que lo
     * respalde ES una anomalía, y el reconciliador la detecta correctamente
     * (lo comprobamos al escribir este test).
     */
    private function teacher(Subject $subject, int $credits = 0): array
    {
        $teacher = $this->userWithRole('teacher');
        $profile = TeacherProfile::create([
            'user_id'           => $teacher->id,
            'is_verified'       => true,
            'credits_available' => $credits,
            'credits_reserved'  => 0,
        ]);
        $profile->subjects()->attach($subject->id);

        if ($credits > 0) {
            CreditTransaction::create([
                'teacher_profile_id' => $profile->id,
                'idempotency_key'    => "teacher:{$profile->id}:seed",
                'type'               => 'deposit',
                'amount'             => $credits,
                'description'        => 'Saldo inicial de prueba',
            ]);
        }

        return [$teacher->fresh(), $profile];
    }

    private function openRequest(Subject $subject): ClassRequest
    {
        $parent  = $this->userWithRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name'     => 'Alumno',
            'last_name'      => 'Prueba',
            'grade_level'    => 'secundaria',
        ]);

        return ClassRequest::create([
            'student_id'  => $student->id,
            'subject_id'  => $subject->id,
            'help_needed' => 'Necesita reforzar el tema.',
            'status'      => 'open',
        ]);
    }

    private function recharge(TeacherProfile $profile): RechargeRequest
    {
        return RechargeRequest::create([
            'teacher_profile_id' => $profile->id,
            'package_code'       => 'inicio',
            'package_name'       => 'Inicio',
            'credits'            => 5,
            'amount_pen'         => '10.00',
            'payment_method'     => 'yape',
            'operation_number'   => fake()->unique()->numerify('#########'),
            'status'             => 'pending',
        ]);
    }

    /** @return array{0: TeacherProfile, 1: Lesson} */
    private function settleableLesson(): array
    {
        $subject = $this->subject();
        // Depósito de 1 crédito que luego queda reservado por la clase: el
        // ledger debe cuadrar (deposit 1 - reservation 1 = 0 disponibles).
        [, $profile] = $this->teacher($subject, credits: 1);
        $profile->update(['credits_available' => 0, 'credits_reserved' => 1]);
        $request = $this->openRequest($subject);
        $request->update(['status' => 'accepted']);

        $lesson = Lesson::create([
            'teacher_profile_id' => $profile->id,
            'student_id'         => $request->student_id,
            'class_request_id'   => $request->id,
            'start_time'         => now()->subDays(10),
            'duration_minutes'   => 60,
            'status'             => 'paid',
        ]);

        CreditTransaction::create([
            'teacher_profile_id' => $profile->id,
            'lesson_id'          => $lesson->id,
            'idempotency_key'    => "lesson:{$lesson->id}:reservation",
            'type'               => 'reservation',
            'amount'             => 1,
            'description'        => 'Reserva por aceptación de clase',
        ]);

        // BUG-3 (docs/MOVA_AUDIT_PHASE0.md): consume() automático (actorId
        // null) ya no acepta liquidar 'paid'/'pending_parent_confirmation'
        // sin reporte pedagógico — una lección "liquidable" de verdad ahora
        // exige uno. Sin esto, los 3 tests de este archivo que llaman
        // consume()/mova:settle-lessons sobre esta lección chocarían con el
        // nuevo guard en vez de ejercitar lo que realmente prueban
        // (idempotencia del ledger bajo repetición).
        LessonReport::create([
            'lesson_id' => $lesson->id,
            'teacher_profile_id' => $profile->id,
            'student_id' => $lesson->student_id,
            'topic_covered' => 'Tema de prueba',
            'student_performance' => 'Buen desempeño',
            'sent_to_parent_at' => now(),
        ]);

        return [$profile, $lesson];
    }
}
