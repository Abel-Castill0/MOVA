<?php

namespace Tests\Feature;

use App\Models\ClassEvent;
use App\Models\ClassRequest;
use App\Models\CreditTransaction;
use App\Models\Lesson;
use App\Models\LessonReport;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Notifications\LessonSettledNotification;
use App\Services\LessonSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * C-1 Entrega 2, Fase 8 — matriz de escenarios de la máquina de estados
 * completa (Fase 3B §4 y §15). Cada test simula el paso del tiempo con
 * Carbon::setTestNow() en vez de fechas relativas frágiles.
 *
 * Limitación declarada honestamente (ver Fase 3B §15): RefreshDatabase usa
 * SQLite en memoria, una sola conexión, un solo proceso. Esto prueba
 * SERIALIZACIÓN determinista (dos llamadas consecutivas en el mismo test),
 * NO concurrencia real (dos procesos compitiendo por el mismo lock). La
 * garantía real bajo concurrencia sigue siendo el UNIQUE de
 * idempotency_key + lockForUpdate(), verificado por diseño, no por este test.
 */
class LessonSettlementScenariosTest extends TestCase
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
            'credits.report_reminder_delay_hours' => 2,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── A: consumo automático desde 'paid' (CON reporte) ────────────────

    public function test_paid_lesson_past_grace_with_report_auto_consumes(): void
    {
        [, $profile, $lesson] = $this->lesson('paid', now()->subDays(8)); // terminó hace 8d > 7d de gracia
        $this->reportFor($lesson);

        $this->artisan('mova:settle-lessons')->assertExitCode(0);

        $lesson->refresh();
        $profile->refresh();
        $this->assertSame('completed', $lesson->status);
        $this->assertNotNull($lesson->credits_settled_at);
        $this->assertSame(0, $profile->credits_reserved);
        $this->assertSame(1, $profile->completed_classes_count);
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "lesson:{$lesson->id}:consumption")->count());
    }

    // ── A2: BUG-3 (docs/MOVA_AUDIT_PHASE0.md) — SIN reporte, escala en vez
    // de auto-completarse. Antes de este fix, este mismo escenario se
    // auto-consumía (era el bug): el reporte pedagógico —"el diferenciador"
    // del producto— podía eludirse simplemente dejando pasar el plazo de
    // gracia, y LessonSettledNotification le prometía al padre "puedes
    // calificar cuando quieras" cuando TeacherReviewController lo habría
    // rechazado con 403 por falta de reporte.

    public function test_paid_lesson_past_grace_with_no_report_escalates_to_review_instead_of_auto_completing(): void
    {
        [, $profile, $lesson] = $this->lesson('paid', now()->subDays(8));

        $this->artisan('mova:settle-lessons')->assertExitCode(0);

        $lesson->refresh();
        $profile->refresh();
        $this->assertSame('needs_admin_review', $lesson->status);
        $this->assertNull($lesson->credits_settled_at);
        // Sigue reservado: escalar no es liquidar, es señalar para revisión.
        $this->assertSame(1, $profile->credits_reserved);
        $this->assertSame(0, $profile->completed_classes_count);
        $this->assertDatabaseMissing('credit_transactions', ['lesson_id' => $lesson->id, 'type' => 'consumption']);
        $this->assertDatabaseHas('class_events', [
            'lesson_id' => $lesson->id,
            'event_type' => 'class_needs_review',
        ]);
    }

    public function test_pending_parent_confirmation_past_grace_with_no_report_also_escalates(): void
    {
        [, $profile, $lesson] = $this->lesson('pending_parent_confirmation', now()->subDays(8));

        $this->artisan('mova:settle-lessons')->assertExitCode(0);

        $lesson->refresh();
        $this->assertSame('needs_admin_review', $lesson->status);
        $this->assertNull($lesson->credits_settled_at);
        $this->assertSame(1, $profile->fresh()->credits_reserved);
    }

    public function test_admin_can_still_force_complete_a_lesson_with_no_report(): void
    {
        // El guard de BUG-3 es exclusivo del camino automático (actorId=null)
        // — un admin humano force-completando a propósito, con actorId real,
        // sigue pudiendo hacerlo sin reporte: es una decisión informada de un
        // humano, no el sistema decidiendo solo.
        [, $profile, $lesson] = $this->lesson('paid', now()->subDays(8));
        $this->artisan('mova:settle-lessons'); // escala a needs_admin_review (sin reporte)
        $this->assertSame('needs_admin_review', $lesson->fresh()->status);

        $admin = $this->userWithRole('admin');
        $this->actingAs($admin)
            ->post(route('admin.lessons.force-complete', $lesson), ['reason' => 'Profesor confirmó por WhatsApp que sí dictó la clase, sin reporte formal.'])
            ->assertRedirect();

        $lesson->refresh();
        $this->assertSame('completed', $lesson->status);
        $this->assertNotNull($lesson->credits_settled_at);
        $this->assertSame(0, $profile->fresh()->credits_reserved);
    }

    // ── B: consumo automático desde 'pending_parent_confirmation' ──────

    public function test_pending_parent_confirmation_past_grace_with_no_review_auto_consumes(): void
    {
        [, $profile, $lesson] = $this->lesson('pending_parent_confirmation', now()->subDays(8));
        $this->reportFor($lesson);

        $this->artisan('mova:settle-lessons')->assertExitCode(0);

        $lesson->refresh();
        $this->assertSame('completed', $lesson->status);
        $this->assertNotNull($lesson->credits_settled_at);
        $this->assertSame(0, $profile->fresh()->credits_reserved);
        // El auto-cierre NUNCA debe crear una reseña — la reseña sigue siendo
        // estrictamente voluntaria, C-1 solo evita que bloquee el crédito.
        $this->assertDatabaseCount('teacher_reviews', 0);
    }

    // ── C: dentro de la ventana de gracia, no se toca ───────────────────

    public function test_paid_lesson_still_within_grace_is_left_untouched(): void
    {
        [, $profile, $lesson] = $this->lesson('paid', now()->subDays(3)); // 3d < 7d de gracia

        $this->artisan('mova:settle-lessons')->assertExitCode(0);

        $lesson->refresh();
        $this->assertSame('paid', $lesson->status);
        $this->assertNull($lesson->credits_settled_at);
        $this->assertSame(1, $profile->fresh()->credits_reserved);
        $this->assertDatabaseMissing('credit_transactions', ['lesson_id' => $lesson->id, 'type' => 'consumption']);
    }

    // ── D: reseña tardía tras auto-liquidación ──────────────────────────

    public function test_late_review_after_auto_settlement_is_accepted_without_a_second_ledger_entry(): void
    {
        [, $profile, $lesson, $parent] = $this->lesson('pending_parent_confirmation', now()->subDays(8));
        $this->reportFor($lesson);

        app(LessonSettlementService::class)->consume($lesson);
        $settledAt = $lesson->fresh()->credits_settled_at;

        // El padre reseña DESPUÉS de que el sistema ya cerró la clase sola.
        $this->actingAs($parent)->post(route('reviews.store', $lesson), ['rating' => 5])
            ->assertRedirect(route('parent.lessons'));

        $this->assertSame(1, \App\Models\TeacherReview::where('lesson_id', $lesson->id)->count());
        // Un solo asiento de consumo — la reseña tardía no debe generar uno
        // segundo (la garantía real: UNIQUE idempotency_key + el guard de
        // credits_settled_at !== null dentro de consume()).
        $this->assertSame(1, CreditTransaction::where('lesson_id', $lesson->id)->where('type', 'consumption')->count());
        // credits_settled_at no debe moverse: la reseña tardía no vuelve a liquidar.
        $this->assertTrue($settledAt->equalTo($lesson->fresh()->credits_settled_at));
    }

    // ── E: escalado de 'scheduled' sin confirmar — cero movimiento financiero ──

    public function test_scheduled_lesson_past_unconfirmed_window_escalates_with_zero_financial_movement(): void
    {
        [, $profile, $lesson] = $this->lesson('scheduled', now()->subDays(8));

        $this->artisan('mova:settle-lessons')->assertExitCode(0);

        $lesson->refresh();
        $profile->refresh();
        $this->assertSame('needs_admin_review', $lesson->status);
        $this->assertNull($lesson->credits_settled_at);
        // Sigue reservado: needs_admin_review NO es una liquidación, es una bandera.
        $this->assertSame(1, $profile->credits_reserved);
        $this->assertDatabaseCount('credit_transactions', 1); // solo la reservation original
        $this->assertDatabaseHas('class_events', ['lesson_id' => $lesson->id, 'event_type' => 'class_needs_review']);
    }

    // ── F: needs_admin_review es liquidable manualmente por un admin ───

    public function test_admin_can_force_complete_a_lesson_stuck_in_needs_admin_review(): void
    {
        [, $profile, $lesson] = $this->lesson('scheduled', now()->subDays(8));
        $this->artisan('mova:settle-lessons'); // escala a needs_admin_review
        $this->assertSame('needs_admin_review', $lesson->fresh()->status);

        $admin = $this->userWithRole('admin');
        $this->actingAs($admin)
            ->post(route('admin.lessons.force-complete', $lesson), ['reason' => 'Profesor confirmó por WhatsApp que sí dictó la clase.'])
            ->assertRedirect();

        $lesson->refresh();
        $this->assertSame('completed', $lesson->status);
        $this->assertNotNull($lesson->credits_settled_at);
        $this->assertSame(0, $profile->fresh()->credits_reserved);
        $this->assertDatabaseHas('class_events', ['lesson_id' => $lesson->id, 'event_type' => 'class_force_completed', 'actor_id' => $admin->id]);
    }

    // ── G: force-refund libera el cupo de mentoría (regresión real detectada en esta entrega) ──

    public function test_admin_force_refund_releases_a_stuck_mentorship_slot(): void
    {
        [$teacher, $profile, $subject] = $this->teacher();
        $profile->update(['mentorship_slots_total' => 2, 'mentorship_slots_taken' => 1, 'credits_reserved' => 1]);
        [$parent, $student] = $this->parentRequest($subject, 'accepted');
        $request = ClassRequest::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Acompañamiento continuo.',
            'status' => 'accepted',
            'is_mentorship' => true,
        ]);
        $lesson = Lesson::create([
            'teacher_profile_id' => $profile->id,
            'student_id' => $student->id,
            'class_request_id' => $request->id,
            'start_time' => now()->subHours(3),
            'duration_minutes' => 60,
            'status' => 'paid',
        ]);
        CreditTransaction::create([
            'teacher_profile_id' => $profile->id,
            'lesson_id' => $lesson->id,
            'idempotency_key' => "lesson:{$lesson->id}:reservation",
            'type' => 'reservation',
            'amount' => 1,
            'description' => 'Reserva por aceptación de clase',
        ]);

        $admin = $this->userWithRole('admin');
        $this->actingAs($admin)
            ->post(route('admin.lessons.force-refund', $lesson), ['reason' => 'El padre nunca confirmó haber recibido la clase, se devuelve por precaución.'])
            ->assertRedirect();

        $profile->refresh();
        $this->assertSame('cancelled', $lesson->fresh()->status);
        $this->assertSame(0, $profile->mentorship_slots_taken);
        $this->assertSame(1, $profile->credits_available);
        $this->assertSame(0, $profile->credits_reserved);
    }

    // ── H: idempotencia del barrido — dos pasadas no duplican nada ─────

    public function test_running_the_sweep_twice_settles_and_escalates_exactly_once(): void
    {
        [, $paidProfile, $paidLesson] = $this->lesson('paid', now()->subDays(8));
        $this->reportFor($paidLesson);
        [, $schedProfile, $schedLesson] = $this->lesson('scheduled', now()->subDays(8));

        $this->artisan('mova:settle-lessons')->assertExitCode(0);
        $firstSettledAt = $paidLesson->fresh()->credits_settled_at;

        // Cruza un límite real de minuto entre pasadas, como en la
        // verificación de idempotencia del seeder (Fase 3A): dos pasadas
        // demasiado rápidas podrían dar un falso negativo.
        Carbon::setTestNow(now()->addMinute());
        $this->artisan('mova:settle-lessons')->assertExitCode(0);

        $this->assertSame(1, CreditTransaction::where('lesson_id', $paidLesson->id)->where('type', 'consumption')->count());
        $this->assertTrue($firstSettledAt->equalTo($paidLesson->fresh()->credits_settled_at));
        $this->assertSame('needs_admin_review', $schedLesson->fresh()->status);
        $this->assertSame(1, ClassEvent::where('lesson_id', $schedLesson->id)->where('event_type', 'class_needs_review')->count());
    }

    // ── I: --dry-run no escribe nada ─────────────────────────────────────

    public function test_dry_run_does_not_write_anything(): void
    {
        [, $profile, $paidLesson] = $this->lesson('paid', now()->subDays(8));
        [, , $schedLesson] = $this->lesson('scheduled', now()->subDays(8));

        $this->artisan('mova:settle-lessons', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame('paid', $paidLesson->fresh()->status);
        $this->assertNull($paidLesson->fresh()->credits_settled_at);
        $this->assertSame('scheduled', $schedLesson->fresh()->status);
        $this->assertDatabaseCount('credit_transactions', 2); // solo las 2 reservations originales
        $this->assertDatabaseCount('class_events', 0);
    }

    // ── J: un fallo en una lección no aborta el resto del barrido ───────

    public function test_a_failure_on_one_lesson_does_not_abort_the_rest_of_the_sweep(): void
    {
        [, , $brokenLesson] = $this->lesson('paid', now()->subDays(8));
        $this->reportFor($brokenLesson);
        [, $healthyProfile, $healthyLesson] = $this->lesson('paid', now()->subDays(8));
        $this->reportFor($healthyLesson);

        // Contamina deliberadamente el ledger de una sola lección para forzar
        // que reservedCreditAmount() lance — simula una anomalía real sin
        // fabricar un resultado falso.
        CreditTransaction::create([
            'teacher_profile_id' => $brokenLesson->teacher_profile_id,
            'lesson_id' => $brokenLesson->id,
            'idempotency_key' => "lesson:{$brokenLesson->id}:reservation:duplicada",
            'type' => 'reservation',
            'amount' => 1,
            'description' => 'Fixture: segunda reserva para forzar una anomalía',
        ]);

        $this->artisan('mova:settle-lessons')->assertExitCode(0);

        // La lección sana sí se liquidó a pesar del error en la otra.
        $this->assertSame('completed', $healthyLesson->fresh()->status);
        $this->assertSame(0, $healthyProfile->fresh()->credits_reserved);
        // La lección rota queda intacta, sin liquidar a ciegas.
        $this->assertSame('paid', $brokenLesson->fresh()->status);
        $this->assertNull($brokenLesson->fresh()->credits_settled_at);
    }

    // ── K: guard de saldo — consume()/refund() nunca empujan credits_reserved a negativo ──
    // Hallazgo real de la pasada de code-review de esta entrega: la versión
    // inicial del servicio no tenía este guard (cada cancel() preexistente sí
    // lo tenía). Sin él, una desincronización ledger↔saldo se traduciría en
    // un credits_reserved negativo silencioso en vez de una excepción.

    public function test_consume_throws_instead_of_driving_credits_reserved_negative(): void
    {
        [, $profile, $lesson] = $this->lesson('paid', now()->subHours(3));
        // Desincroniza el saldo respecto al ledger a propósito (nunca ocurre
        // en operación sana: es la anomalía que el guard debe atrapar).
        $profile->update(['credits_reserved' => 0]);

        $this->expectException(\RuntimeException::class);
        app(LessonSettlementService::class)->consume($lesson);

        $this->assertSame(0, $profile->fresh()->credits_reserved);
        $this->assertNull($lesson->fresh()->credits_settled_at);
    }

    public function test_refund_throws_instead_of_driving_credits_reserved_negative(): void
    {
        [, $profile, $lesson] = $this->lesson('scheduled', now()->subHours(3));
        $profile->update(['credits_reserved' => 0]);

        $this->expectException(\RuntimeException::class);
        app(LessonSettlementService::class)->refund($lesson);

        $this->assertSame(0, $profile->fresh()->credits_reserved);
        $this->assertNull($lesson->fresh()->credits_settled_at);
    }

    // ── L: Decisión de negocio #3 (Fase 3B §17) — notificar el cierre automático ──

    public function test_notify_true_with_a_human_actor_is_rejected(): void
    {
        // Hallazgo de la pasada de code-review de esta decisión: notify=true
        // combinado con un actorId real enviaría "se cerró automáticamente"
        // para una acción humana — debe rechazarse en código, no solo en
        // comentario.
        [, , $lesson] = $this->lesson('paid', now()->subHours(3));

        $this->expectException(\InvalidArgumentException::class);
        app(LessonSettlementService::class)->consume($lesson, actorId: 999, notify: true);
    }

    public function test_auto_settlement_notifies_both_teacher_and_parent(): void
    {
        Notification::fake();

        [$teacher, , $lesson, $parent] = $this->lesson('paid', now()->subDays(8));
        $this->reportFor($lesson);

        $this->artisan('mova:settle-lessons')->assertExitCode(0);

        Notification::assertSentTo($teacher, LessonSettledNotification::class);
        Notification::assertSentTo($parent, LessonSettledNotification::class);
        Notification::assertCount(2);
    }

    public function test_settling_via_review_does_not_send_the_auto_settlement_notification(): void
    {
        Notification::fake();

        [$teacher, , $lesson, $parent] = $this->lesson('pending_parent_confirmation', now()->subHours(2));
        $this->reportFor($lesson);

        $this->actingAs($parent)->post(route('reviews.store', $lesson), ['rating' => 5])
            ->assertRedirect(route('parent.lessons'));

        $this->assertNotNull($lesson->fresh()->credits_settled_at);
        // La reseña sí notifica al profesor (TeacherReviewReceivedNotification,
        // sin cambios) — lo que NO debe pasar es que ADEMÁS se dispare la
        // notificación de cierre automático, que es exclusiva del scheduler.
        Notification::assertNotSentTo($parent, LessonSettledNotification::class);
        Notification::assertNotSentTo($teacher, LessonSettledNotification::class);
    }

    public function test_admin_force_complete_does_not_send_the_auto_settlement_notification(): void
    {
        Notification::fake();

        [$teacher, , $lesson] = $this->lesson('paid', now()->subHours(3));
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->post(route('admin.lessons.force-complete', $lesson), ['reason' => 'Confirmado manualmente por soporte.'])
            ->assertRedirect();

        $this->assertNotNull($lesson->fresh()->credits_settled_at);
        Notification::assertNotSentTo($teacher, LessonSettledNotification::class);
    }

    public function test_settled_notification_never_carries_a_jitsi_room_or_meet_jitsi_url(): void
    {
        Notification::fake();

        [$teacher, , $lesson, $parent] = $this->lesson('paid', now()->subDays(8));
        $this->reportFor($lesson);
        // Simula que la clase sí tenía sala asignada — exactamente el dato que
        // C-3 (533a799) prohibió que viajara en el payload de una notificación.
        $lesson->forceFill(['jitsi_room' => 'mova-lesson-secret-room'])->save();

        $this->artisan('mova:settle-lessons')->assertExitCode(0);

        Notification::assertSentTo($teacher, LessonSettledNotification::class, function ($notification, $channels) use ($teacher) {
            $array = $notification->toArray($teacher);
            $payload = json_encode($array);

            $this->assertStringNotContainsString('jitsi_room', $payload);
            $this->assertStringNotContainsString('jitsi_password', $payload);
            $this->assertStringNotContainsString('meet.jit.si', $payload);
            $this->assertArrayNotHasKey('jitsi_room', $array);

            return true;
        });
    }

    public function test_running_the_sweep_twice_sends_the_notification_only_once(): void
    {
        Notification::fake();

        [$teacher, , $lesson, $parent] = $this->lesson('paid', now()->subDays(8));
        $this->reportFor($lesson);

        $this->artisan('mova:settle-lessons')->assertExitCode(0);
        // Mismo cruce de minuto real que el resto de los tests de idempotencia
        // de esta clase — evita el falso negativo ya documentado (Fase 3A).
        Carbon::setTestNow(now()->addMinute());
        $this->artisan('mova:settle-lessons')->assertExitCode(0);

        Notification::assertSentToTimes($teacher, LessonSettledNotification::class, 1);
        Notification::assertSentToTimes($parent, LessonSettledNotification::class, 1);
    }

    // ── Helpers (mismo patrón que MonetizationIntegrityTest) ───────────

    private function teacher(int $availableCredits = 0): array
    {
        $teacher = $this->userWithRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_verified' => true,
            'credits_available' => $availableCredits,
            'credits_reserved' => 0,
        ]);
        $subject = Subject::create([
            'name' => 'Materia '.fake()->unique()->numerify('####'),
            'level' => 'secundaria',
        ]);
        $profile->subjects()->attach($subject->id);

        return [$teacher, $profile, $subject];
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['password' => 'password']);
        $user->assignRole($role);

        return $user;
    }

    private function parentRequest(Subject $subject, string $status): array
    {
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
            'status' => $status,
        ]);

        return [$parent, $student, $request];
    }

    private function lesson(string $status, Carbon $startTime, int $reservedCredits = 1): array
    {
        [$teacher, $profile, $subject] = $this->teacher();
        $profile->update(['credits_reserved' => $reservedCredits]);
        [$parent, $student, $request] = $this->parentRequest($subject, 'accepted');
        $lesson = Lesson::create([
            'teacher_profile_id' => $profile->id,
            'student_id' => $student->id,
            'class_request_id' => $request->id,
            'start_time' => $startTime,
            'duration_minutes' => 60,
            'status' => $status,
        ]);

        CreditTransaction::create([
            'teacher_profile_id' => $profile->id,
            'lesson_id' => $lesson->id,
            'idempotency_key' => "lesson:{$lesson->id}:reservation",
            'type' => 'reservation',
            'amount' => $reservedCredits,
            'description' => 'Reserva por aceptación de clase',
        ]);

        return [$teacher, $profile, $lesson, $parent];
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
}
