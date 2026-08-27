<?php

namespace Tests\Feature;

use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Notifications\ClassReminderNotification;
use App\Notifications\UnansweredRequestNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * F-04 (segunda ronda) — Recuperabilidad del claim.
 *
 * La primera corrección eliminó los duplicados pero introdujo un riesgo peor:
 * marcaba el recordatorio como enviado ANTES de despachar y fuera de toda
 * transacción, así que un fallo de despacho consumía el marcador y el aviso se
 * perdía para siempre (el barrido filtra por whereNull). El comentario del
 * código incluso lo asumía: "preferimos perder un recordatorio a enviarlo dos
 * veces". Era una concesión innecesaria.
 *
 * Ahora claim y despacho comparten transacción. Estos tests inyectan fallos
 * reales en el despacho y comprueban que el marcador queda LIBERADO, de modo
 * que la siguiente pasada reintenta y el aviso acaba llegando.
 *
 * Premisa verificada: las 21 App\Notifications implementan ShouldQueue, así que
 * notify() no hace llamadas externas — solo inserta en `jobs`, en la misma
 * conexión. Por eso la transacción no envuelve ninguna operación de red.
 */
class ReminderClaimRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Sustituye el gestor de canales por uno que revienta al notificar,
     * simulando un fallo de despacho (worker sin cola, base saturada, error de
     * serialización...). Es el equivalente observable a "el proceso murió justo
     * después del claim".
     */
    private function breakNotificationDispatch(): void
    {
        $this->app->extend(ChannelManager::class, fn () => new class($this->app) extends ChannelManager
        {
            public function send($notifiables, $notification)
            {
                throw new RuntimeException('Fallo simulado de despacho');
            }

            public function sendNow($notifiables, $notification, array $channels = null)
            {
                throw new RuntimeException('Fallo simulado de despacho');
            }
        });
    }

    // ── Premisa del diseño ───────────────────────────────────────────────

    public function test_every_notification_is_queued_so_no_external_call_happens_inside_the_transaction(): void
    {
        // Si alguna notificación dejara de ser ShouldQueue, notify() haría la
        // llamada HTTP DENTRO de la transacción del claim: exactamente lo que
        // no debe pasar. Este test es el guardián de esa premisa.
        $offenders = [];

        foreach (glob(app_path('Notifications/*.php')) as $path) {
            $class = 'App\\Notifications\\'.basename($path, '.php');

            if (!class_exists($class) || (new \ReflectionClass($class))->isAbstract()) {
                continue;
            }

            if (!is_subclass_of($class, ShouldQueue::class)) {
                $offenders[] = $class;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Estas notificaciones NO son ShouldQueue, así que harían la llamada externa dentro de la "
            ."transacción del claim: \n".implode("\n", $offenders)
        );
    }

    public function test_the_health_check_flags_a_queue_driver_that_breaks_claim_atomicity(): void
    {
        // La atomicidad de claimAndDispatch() depende de que el INSERT del job
        // caiga en la misma conexión que el marcador. En producción eso es
        // QUEUE_CONNECTION=database; la suite corre con 'sync' (que además
        // ejecuta el envío inline, el caso MÁS duro para estos tests).
        //
        // Como no se puede fijar el driver de producción desde aquí, lo que se
        // verifica es el guardián: que mova:health-check avise si alguien
        // cambia a un driver que rompa la premisa.
        config(['queue.default' => 'redis']);

        $this->artisan('mova:health-check')
            ->expectsOutputToContain('QUEUE_NOT_TRANSACTIONAL')
            ->assertExitCode(1);
    }

    // ── El claim se libera si el despacho falla ──────────────────────────

    public function test_a_failed_dispatch_releases_the_24h_claim(): void
    {
        $lesson = $this->scheduledLesson(now()->addHours(24));
        $this->breakNotificationDispatch();

        $this->artisan('classmate:send-reminders')->assertExitCode(0);

        $this->assertNull(
            $lesson->fresh()->reminder_24h_sent_at,
            'Si el despacho falla, el marcador debe quedar liberado — si no, el aviso se pierde para siempre.'
        );
    }

    public function test_a_failed_dispatch_releases_the_2h_claim(): void
    {
        $lesson = $this->scheduledLesson(now()->addHours(2));
        $this->breakNotificationDispatch();

        $this->artisan('classmate:send-reminders');

        $this->assertNull($lesson->fresh()->reminder_2h_sent_at);
    }

    public function test_a_failed_dispatch_releases_the_10m_claim(): void
    {
        $lesson = $this->scheduledLesson(now()->addMinutes(5));
        $this->breakNotificationDispatch();

        $this->artisan('classmate:send-reminders');

        $this->assertFalse((bool) $lesson->fresh()->reminder_sent);
    }

    public function test_a_failed_dispatch_releases_the_pending_report_claim(): void
    {
        config(['credits.report_reminder_delay_hours' => 2, 'credits.settlement_grace_days' => 7]);
        $lesson = $this->scheduledLesson(now()->subHours(5));
        $lesson->update(['status' => 'paid']);

        $this->breakNotificationDispatch();
        $this->artisan('classmate:send-reminders');

        $this->assertNull($lesson->fresh()->report_reminder_sent_at);
    }

    public function test_a_failed_dispatch_releases_the_unanswered_request_claim(): void
    {
        $subject = $this->subject();
        $this->verifiedTeacher($subject);
        $request = $this->openRequest($subject, hoursOld: 13);

        $this->breakNotificationDispatch();
        $this->artisan('classmate:send-reminders');

        $this->assertNull($request->fresh()->request_reminder_sent_at);
    }

    // ── Y el reintento posterior sí entrega ──────────────────────────────

    public function test_the_reminder_is_delivered_on_the_next_run_after_a_failure(): void
    {
        $lesson = $this->scheduledLesson(now()->addHours(24));

        // Pasada 1: el despacho falla, el claim se libera.
        $this->breakNotificationDispatch();
        $this->artisan('classmate:send-reminders');
        $this->assertNull($lesson->fresh()->reminder_24h_sent_at);

        // Pasada 2: sistema sano. El aviso llega — no se perdió.
        // Notification::fake() reemplaza el ChannelManager roto sin tocar la
        // base de datos (refreshApplication() la vaciaría: es SQLite en memoria).
        Notification::fake();
        $this->artisan('classmate:send-reminders');

        Notification::assertSentTimes(ClassReminderNotification::class, 2);
        $this->assertNotNull($lesson->fresh()->reminder_24h_sent_at);
    }

    // ── Sin fallo, sigue sin duplicar (no se perdió lo ganado) ───────────

    public function test_a_successful_dispatch_still_marks_the_claim_and_does_not_duplicate(): void
    {
        Notification::fake();
        $lesson = $this->scheduledLesson(now()->addHours(24));

        $this->artisan('classmate:send-reminders');
        $this->artisan('classmate:send-reminders');

        Notification::assertSentTimes(ClassReminderNotification::class, 2); // profesor + padre, una vez
        $this->assertNotNull($lesson->fresh()->reminder_24h_sent_at);
    }

    public function test_each_eligible_teacher_receives_the_unanswered_alert_exactly_once(): void
    {
        // Con 3 profesores elegibles deben salir 3 avisos, no 3×3: la
        // resolución de destinatarios no debe multiplicarse por joins.
        Notification::fake();
        $subject = $this->subject();
        $this->verifiedTeacher($subject);
        $this->verifiedTeacher($subject);
        $this->verifiedTeacher($subject);
        $this->openRequest($subject, hoursOld: 13);

        $this->artisan('classmate:send-reminders');

        Notification::assertSentTimes(UnansweredRequestNotification::class, 3);
    }

    // ── Helpers ──────────────────────────────────────────────────────────


    private function subject(): Subject
    {
        return Subject::create([
            'name' => 'Materia '.fake()->unique()->numerify('####'),
            'level' => 'secundaria',
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['password' => 'password']);
        $user->assignRole($role);

        return $user;
    }

    private function verifiedTeacher(Subject $subject): User
    {
        $teacher = $this->userWithRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_verified' => true,
            'credits_available' => 0,
            'credits_reserved' => 0,
        ]);
        $profile->subjects()->attach($subject->id);

        return $teacher->fresh('teacherProfile');
    }

    private function openRequest(Subject $subject, int $hoursOld): ClassRequest
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
            'status' => 'open',
        ]);

        $request->created_at = now()->subHours($hoursOld);
        $request->save();

        return $request;
    }

    private function scheduledLesson(Carbon $startTime): Lesson
    {
        $subject = $this->subject();
        $teacher = $this->verifiedTeacher($subject);
        $parent = $this->userWithRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Alumno',
            'last_name' => 'Prueba',
            'grade_level' => 'secundaria',
        ]);

        return Lesson::create([
            'teacher_profile_id' => $teacher->teacherProfile->id,
            'student_id' => $student->id,
            'start_time' => $startTime,
            'duration_minutes' => 60,
            'status' => 'scheduled',
        ]);
    }
}
