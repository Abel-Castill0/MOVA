<?php

namespace Tests\Feature;

use App\Models\ClassEvent;
use App\Models\ClassRequest;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Notifications\ClassRequestExpiredNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * §14 — Expiración automática de solicitudes abiertas.
 *
 * Antes, una `ClassRequest` en `open` no tenía salida: si nadie la aceptaba ni
 * la rechazaba se quedaba abierta para siempre, ensuciando la bandeja del
 * profesor y el contador de demanda del panel de admin
 * (docs/MOVA_SYSTEM_MAP.md R-12).
 */
class ClassRequestExpiryTest extends TestCase
{
    use RefreshDatabase;

    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role);
        }

        $this->subject = Subject::firstOrCreateByName('Matemática');
    }

    /**
     * @return array{0: User, 1: Student}
     */
    private function parentWithStudent(): array
    {
        $parent = User::factory()->create();
        $parent->assignRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'grade_level' => 'secundaria',
        ]);

        return [$parent, $student];
    }

    private function request(Student $student, string $status, int $hoursAgo): ClassRequest
    {
        $request = ClassRequest::create([
            'student_id' => $student->id,
            'subject_id' => $this->subject->id,
            'help_needed' => 'Necesita apoyo con álgebra.',
            'status' => $status,
        ]);

        // `created_at` no es asignable en masa aquí: se fija con una escritura
        // directa para simular el paso del tiempo.
        DB::table('class_requests')->where('id', $request->id)
            ->update(['created_at' => now()->subHours($hoursAgo)]);

        return $request->fresh();
    }

    // ── Comportamiento base ──────────────────────────────────────────────

    public function test_an_open_request_past_the_window_expires(): void
    {
        Notification::fake();
        [$parent, $student] = $this->parentWithStudent();
        $request = $this->request($student, 'open', 25);

        $this->artisan('mova:expire-class-requests')->assertExitCode(0);

        $this->assertSame('expired', $request->fresh()->status);
        Notification::assertSentTo($parent, ClassRequestExpiredNotification::class);
    }

    public function test_an_open_request_inside_the_window_is_untouched(): void
    {
        Notification::fake();
        [$parent, $student] = $this->parentWithStudent();
        $request = $this->request($student, 'open', 23);

        $this->artisan('mova:expire-class-requests')->assertExitCode(0);

        $this->assertSame('open', $request->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_the_window_is_configurable(): void
    {
        Notification::fake();
        [, $student] = $this->parentWithStudent();
        $request = $this->request($student, 'open', 10);

        config(['class_requests.expiry_hours' => 8]);

        $this->artisan('mova:expire-class-requests')->assertExitCode(0);

        $this->assertSame('expired', $request->fresh()->status);
    }

    /**
     * La expiración DEBE ocurrir después del recordatorio de 12 h, o el
     * profesor recibiría un "tienes una solicitud sin responder" sobre algo que
     * ya no puede aceptar.
     */
    public function test_the_expiry_window_is_wider_than_the_reminder_window(): void
    {
        $this->assertGreaterThan(
            12,
            (int) config('class_requests.expiry_hours'),
            'El recordatorio de solicitudes sin responder se envía a las 12 h (SendClassReminders).'
        );
    }

    // ── Estados que NO deben expirar ─────────────────────────────────────

    /** @dataProvider nonExpirableStatuses */
    public function test_only_open_requests_expire(string $status): void
    {
        Notification::fake();
        [, $student] = $this->parentWithStudent();
        $request = $this->request($student, $status, 100);

        $this->artisan('mova:expire-class-requests')->assertExitCode(0);

        $this->assertSame($status, $request->fresh()->status);
        Notification::assertNothingSent();
    }

    public static function nonExpirableStatuses(): array
    {
        return [
            'aceptada (tiene clase y créditos reservados)' => ['accepted'],
            'rechazada por el padre' => ['rejected'],
            'rechazada por el profesor' => ['teacher_rejected'],
            // Espera al PADRE, no a un profesor: expirarla castigaría al
            // usuario por no revisar su propia bandeja.
            'pendiente de aprobación del padre' => ['pending_parent_approval'],
        ];
    }

    // ── Idempotencia y carreras ──────────────────────────────────────────

    public function test_running_the_sweep_twice_expires_and_notifies_only_once(): void
    {
        Notification::fake();
        [$parent, $student] = $this->parentWithStudent();
        $request = $this->request($student, 'open', 30);

        $this->artisan('mova:expire-class-requests')->assertExitCode(0);
        $this->artisan('mova:expire-class-requests')->assertExitCode(0);
        $this->artisan('mova:expire-class-requests')->assertExitCode(0);

        $this->assertSame('expired', $request->fresh()->status);
        Notification::assertSentToTimes($parent, ClassRequestExpiredNotification::class, 1);

        $this->assertSame(
            1,
            ClassEvent::where('class_request_id', $request->id)->where('event_type', 'request_expired')->count()
        );
    }

    /**
     * CARRERA ACEPTACIÓN vs EXPIRACIÓN, en el sentido peligroso: si la
     * expiración pisara una solicitud ya aceptada, quedaría una Lesson viva
     * colgando de una solicitud `expired` — un estado que la máquina de estados
     * no contempla.
     */
    public function test_a_request_accepted_before_the_sweep_is_never_expired(): void
    {
        Notification::fake();
        [, $student] = $this->parentWithStudent();
        $request = $this->request($student, 'open', 30);

        // El profesor gana la carrera.
        $request->update(['status' => 'accepted']);

        $this->artisan('mova:expire-class-requests')->assertExitCode(0);

        $this->assertSame('accepted', $request->fresh()->status);
    }

    /**
     * Y en el sentido inverso: una solicitud ya expirada no puede aceptarse.
     * Lo garantiza el re-chequeo `status !== 'open'` de LessonController::store().
     */
    public function test_an_expired_request_can_no_longer_be_accepted(): void
    {
        Notification::fake();
        [, $student] = $this->parentWithStudent();
        $request = $this->request($student, 'open', 30);

        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'hourly_rate' => 20,
            'credits_available' => 10,
            'is_verified' => true,
        ]);
        $profile->subjects()->sync([$this->subject->id => ['specific_rate' => null]]);

        $this->artisan('mova:expire-class-requests')->assertExitCode(0);

        $this->actingAs($teacher)
            ->post(route('lessons.store'), [
                'class_request_id' => $request->id,
                'start_time' => now()->addDays(2)->toIso8601String(),
                'duration_minutes' => 60,
            ])
            ->assertSessionHasErrors('accept');

        $this->assertSame(0, \App\Models\Lesson::count());
        $this->assertSame(10, $profile->fresh()->credits_available, 'No se reservan créditos.');
    }

    // ── Efectos ──────────────────────────────────────────────────────────

    public function test_expiring_never_touches_credits(): void
    {
        Notification::fake();
        [, $student] = $this->parentWithStudent();
        $this->request($student, 'open', 30);

        $this->artisan('mova:expire-class-requests')->assertExitCode(0);

        // Una solicitud `open` nunca tuvo créditos reservados: la reserva
        // ocurre al ACEPTAR. Expirar es puramente un cambio de estado.
        $this->assertDatabaseCount('credit_transactions', 0);
    }

    public function test_the_expiry_is_audited(): void
    {
        Notification::fake();
        [, $student] = $this->parentWithStudent();
        $request = $this->request($student, 'open', 30);

        $this->artisan('mova:expire-class-requests')->assertExitCode(0);

        $event = ClassEvent::where('class_request_id', $request->id)
            ->where('event_type', 'request_expired')
            ->first();

        $this->assertNotNull($event);
        $this->assertNull($event->actor_id, 'La expiración no tiene actor humano.');
    }

    public function test_dry_run_writes_nothing(): void
    {
        Notification::fake();
        [, $student] = $this->parentWithStudent();
        $request = $this->request($student, 'open', 30);

        $this->artisan('mova:expire-class-requests --dry-run')->assertExitCode(0);

        $this->assertSame('open', $request->fresh()->status);
        Notification::assertNothingSent();
        $this->assertSame(0, ClassEvent::where('event_type', 'request_expired')->count());
    }

    /**
     * El estado debe poder persistirse de verdad: si el enum del esquema no lo
     * incluyera, la escritura fallaría (CHECK constraint en SQLite, truncado en
     * MySQL).
     */
    public function test_the_expired_status_is_accepted_by_the_schema(): void
    {
        [, $student] = $this->parentWithStudent();
        $request = $this->request($student, 'open', 1);

        $request->update(['status' => 'expired']);

        $this->assertSame('expired', $request->fresh()->status);
    }
}
