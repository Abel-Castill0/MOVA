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

    // ── LEGACY_PRE_LEDGER ─────────────────────────────────────────────────────
    //
    // Hallazgo del rescate de Railway (AZ-2.11/AZ-2.12): el snapshot real
    // recuperado tiene 3 lecciones sin ningún asiento, todas creadas semanas
    // antes de que `credit_transactions` existiera (migración de
    // 2026-07-08). Un NO_LEDGER ahí no es la misma anomalía que un NO_LEDGER
    // de hoy: la tabla ni siquiera existía. El corte es por fecha
    // (LedgerReconciliation::LEDGER_EPOCH), nunca por status, para no abrir
    // un hueco amplio en STATES_ALLOWED_WITHOUT_LEDGER.

    public function test_a_pre_ledger_class_without_any_transaction_is_accepted_as_legacy(): void
    {
        [, , $lesson] = $this->lessonWithoutLedger();
        $this->backdateBeforeLedgerEpoch($lesson);

        $classified = (new LedgerReconciliation)->classifyLessons();

        $this->assertSame(LedgerReconciliation::LEGACY_PRE_LEDGER, $classified[0]['classification']);

        $report = (new LedgerReconciliation)->run();
        $this->assertTrue($report['healthy'], 'Una lección legacy pre-ledger no debe contar como anomalía.');
        $this->assertSame(0, $report['counts'][LedgerReconciliation::NO_LEDGER]);
        $this->assertSame(1, $report['counts'][LedgerReconciliation::LEGACY_PRE_LEDGER]);

        $this->artisan('mova:reconcile-ledger')
            ->expectsOutputToContain('GREEN')
            ->assertExitCode(0);
    }

    public function test_a_post_ledger_class_without_any_transaction_still_stays_red(): void
    {
        // Misma forma (sin transacciones) pero creada DESPUÉS del epoch: la
        // tabla ya existía, así que sigue siendo la anomalía original.
        [, , $lesson] = $this->lessonWithoutLedger();

        $classified = (new LedgerReconciliation)->classifyLessons();
        $this->assertSame(LedgerReconciliation::NO_LEDGER, $classified[0]['classification']);
        $this->assertNotSame(LedgerReconciliation::LEGACY_PRE_LEDGER, $classified[0]['classification']);

        $this->artisan('mova:reconcile-ledger')
            ->expectsOutputToContain('RED')
            ->assertExitCode(1);
    }

    public function test_a_pre_ledger_class_with_a_real_reservation_is_not_misclassified_as_legacy(): void
    {
        // El corte por fecha solo se aplica cuando NO hay ningún asiento.
        // Una lección vieja con una reserva real sigue siendo OPEN_RESERVATION.
        [, , $lesson] = $this->lessonWithReservation();
        $this->backdateBeforeLedgerEpoch($lesson);

        $classified = (new LedgerReconciliation)->classifyLessons();

        $this->assertSame(LedgerReconciliation::OPEN_RESERVATION, $classified[0]['classification']);
    }

    public function test_a_pre_ledger_class_that_was_consumed_is_still_healthy_consumed(): void
    {
        [$profile, , $lesson] = $this->lessonWithReservation();
        $this->backdateBeforeLedgerEpoch($lesson);
        $this->closeWith($profile, $lesson, 'consumption');

        $classified = (new LedgerReconciliation)->classifyLessons();

        $this->assertSame(LedgerReconciliation::HEALTHY_CONSUMED, $classified[0]['classification']);
    }

    public function test_a_pre_ledger_class_that_was_refunded_is_still_healthy_refunded(): void
    {
        [$profile, , $lesson] = $this->lessonWithReservation();
        $this->backdateBeforeLedgerEpoch($lesson);
        $this->closeWith($profile, $lesson, 'refund');

        $classified = (new LedgerReconciliation)->classifyLessons();

        $this->assertSame(LedgerReconciliation::HEALTHY_REFUNDED, $classified[0]['classification']);
    }

    public function test_a_pre_ledger_class_with_an_impossible_combination_is_still_invalid(): void
    {
        // El corte por fecha no debe volver permisiva ninguna combinación
        // imposible: solo exime el caso "cero asientos".
        [$profile, , $lesson] = $this->lessonWithReservation();
        $this->backdateBeforeLedgerEpoch($lesson);
        $this->closeWith($profile, $lesson, 'consumption');
        $this->closeWith($profile, $lesson, 'refund');

        $classified = (new LedgerReconciliation)->classifyLessons();

        $this->assertSame(LedgerReconciliation::INVALID, $classified[0]['classification']);

        $this->artisan('mova:reconcile-ledger')
            ->expectsOutputToContain('RED')
            ->assertExitCode(1);
    }

    /** @return array{0: TeacherProfile, 1: User, 2: Lesson} */
    private function lessonWithoutLedger(): array
    {
        $teacher = $this->userWithRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_verified' => true,
            'credits_available' => 0,
            'credits_reserved' => 0,
        ]);

        $subject = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'secundaria']);
        $parent = $this->userWithRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Alumno',
            'last_name' => 'Legacy',
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
            'status' => 'completed',
        ]);

        return [$profile, $teacher, $lesson];
    }

    /**
     * Retrocede `created_at` a antes de LedgerReconciliation::LEDGER_EPOCH,
     * escribiendo directamente por query builder (igual que lee
     * LedgerReconciliation), sin pasar por mutadores/guarded de Eloquent.
     */
    private function backdateBeforeLedgerEpoch(Lesson $lesson): void
    {
        DB::table('classes')->where('id', $lesson->id)->update([
            'created_at' => '2026-06-24 08:15:09',
        ]);
    }

    // ── Reversals de recarga (Mercado Pago) ──────────────────────────────────
    //
    // Bug real encontrado auditando la integración de Mercado Pago
    // (2026-08-29): CreditTransaction::reversal existe desde
    // 2026_08_23_000001_add_reversal_type_to_credit_transactions.php —
    // esa misma migración documenta explícitamente que 'reversal' necesitaba
    // su propio tipo, con amount negativo, PRECISAMENTE porque
    // derivedAvailableFor() SUMA 'refund' y una reversión de depósito debe
    // RESTAR. La migración hizo su parte; derivedAvailableFor() nunca se
    // actualizó para restar 'reversal' — así que cualquier reversión real
    // (un chargeback/refund reportado por un proveedor de pago) produce un
    // falso "DESCUADRE DE SALDO" en mova:reconcile-ledger, para siempre,
    // cada vez que se corra el comando.

    public function test_reconciliation_includes_reversal_when_deriving_available_balance(): void
    {
        [$profile, , $lesson, $recharge] = $this->lessonWithReservationAndRevertedRecharge();

        // Semántica confirmada leyendo el código real (no asumida):
        // deposit +25, reservation -20, consumption 0 sobre available,
        // reversal -25 → disponible real = -20.
        $this->assertSame(-20, $profile->fresh()->credits_available);

        $reconciliation = new LedgerReconciliation;
        $this->assertSame(
            -20,
            $reconciliation->derivedAvailableFor($profile->id),
            'derivedAvailableFor() debe restar reversal — antes del fix da 5 (ignora la reversión).'
        );

        $mismatches = collect($reconciliation->reconcileProfiles())
            ->firstWhere('teacher_profile_id', $profile->id);
        $this->assertTrue(
            $mismatches['available_ok'],
            'Una reversión legítima no debe reportarse como descuadre de saldo.'
        );

        $this->artisan('mova:reconcile-ledger')
            ->expectsOutputToContain('GREEN')
            ->assertExitCode(0);
    }

    public function test_reconciliation_still_detects_a_real_mismatch_alongside_a_reversal(): void
    {
        // Control negativo pedido explícitamente: incluir 'reversal' en la
        // fórmula no debe volver la reconciliación permisiva con CUALQUIER
        // saldo negativo — solo con el que el propio ledger explica.
        [$profile] = $this->lessonWithReservationAndRevertedRecharge();

        // Saldo real derivado es -20; se corrompe a -19 (un crédito de más
        // que el ledger no respalda).
        TeacherProfile::whereKey($profile->id)->update(['credits_available' => -19]);

        $reconciliation = new LedgerReconciliation;
        $mismatch = collect($reconciliation->reconcileProfiles())
            ->firstWhere('teacher_profile_id', $profile->id);
        $this->assertFalse($mismatch['available_ok']);

        $this->artisan('mova:reconcile-ledger')->assertExitCode(1);
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

    /**
     * Escenario completo pedido en la auditoría: deposit +25 (vía el
     * servicio real, no una fila fabricada), reservation -20 para una
     * lección real, consumption que la cierra, y luego una reversión real
     * de esa misma recarga (RechargeApprovalService::reverse() — el mismo
     * camino que un webhook de chargeback usaría) después de que el
     * profesor ya gastó los créditos reservados.
     *
     * @return array{0: TeacherProfile, 1: User, 2: Lesson, 3: \App\Models\RechargeRequest}
     */
    private function lessonWithReservationAndRevertedRecharge(): array
    {
        $teacher = $this->userWithRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_verified' => true,
            'credits_available' => 0,
            'credits_reserved' => 0,
        ]);

        $recharge = \App\Models\RechargeRequest::create([
            'teacher_profile_id' => $profile->id,
            'package_code' => 'pro',
            'package_name' => 'Pro',
            'credits' => 25,
            'amount_pen' => '69.90',
            'payment_method' => 'fake',
            'operation_number' => 'OP'.$profile->id.'REV',
            'operation_number_normalized' => 'OP'.$profile->id.'REV',
            'status' => 'pending',
        ]);
        app(\App\Services\RechargeApprovalService::class)->credit($recharge, null);
        $this->assertSame(25, $profile->fresh()->credits_available);

        $subject = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'secundaria']);
        $parent = $this->userWithRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Alumno',
            'last_name' => 'Reversión',
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
            'price_frozen_pen' => 400.00,
            'status' => 'scheduled',
        ]);

        // Reserva 20 (deja available=5) y consumo que la cierra (reserved
        // vuelve a 0, available no cambia — mismo patrón que
        // LessonSettlementService::consume()).
        CreditTransaction::create([
            'teacher_profile_id' => $profile->id,
            'lesson_id' => $lesson->id,
            'idempotency_key' => "lesson:{$lesson->id}:reservation",
            'type' => 'reservation',
            'amount' => 20,
            'description' => 'Reserva por aceptación de clase',
        ]);
        $profile->update(['credits_available' => 5, 'credits_reserved' => 20]);
        $this->closeWith($profile, $lesson, 'consumption', amount: 20);
        $profile->update(['credits_reserved' => 0]);

        // Reversión real de la recarga original — el profesor ya gastó los
        // créditos, así que esto deja available en negativo a propósito
        // (RechargeApprovalService::reverse(), documentado como
        // comportamiento deliberado).
        app(\App\Services\RechargeApprovalService::class)->reverse($recharge->fresh(), null, 'Chargeback de prueba');

        return [$profile->fresh(), $teacher, $lesson, $recharge->fresh()];
    }

    private function closeWith(TeacherProfile $profile, Lesson $lesson, string $type, int $amount = 1): void
    {
        CreditTransaction::create([
            'teacher_profile_id' => $profile->id,
            'lesson_id' => $lesson->id,
            'idempotency_key' => "lesson:{$lesson->id}:".($type === 'refund' ? 'release' : $type),
            'type' => $type,
            'amount' => $amount,
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
