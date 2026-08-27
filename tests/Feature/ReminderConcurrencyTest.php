<?php

namespace Tests\Feature;

use App\Models\ClassOffer;
use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Notifications\ClassReminderNotification;
use App\Notifications\UnansweredRequestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * F-04 / F-10 / GAP-07 — `classmate:send-reminders` corre cada minuto y sus
 * barridos pueden superar los 60s con volumen real. El patrón anterior
 * (SELECT -> notify() -> update()) permitía que dos ejecuciones solapadas
 * leyeran el mismo conjunto con el marcador en NULL y notificaran ambas.
 *
 * Limitación declarada (igual que LessonSettlementScenariosTest): SQLite en
 * memoria, un proceso. Esto prueba que el claim es ATÓMICO y que una segunda
 * pasada no re-notifica — no simula dos procesos compitiendo por el mismo
 * lock del motor. La garantía real es que el claim es un UPDATE condicional
 * evaluado por el motor de base de datos, no una lectura seguida de escritura.
 */
class ReminderConcurrencyTest extends TestCase
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

    // ── F-04: no duplicar recordatorios de clase ─────────────────────────

    public function test_a_second_run_does_not_resend_the_24h_reminder(): void
    {
        Notification::fake();
        $lesson = $this->scheduledLesson(now()->addHours(24));

        $this->artisan('classmate:send-reminders')->assertExitCode(0);
        $this->artisan('classmate:send-reminders')->assertExitCode(0);

        // notifyBoth avisa a profesor y padre: 2 destinatarios, 1 vez cada uno.
        Notification::assertSentTimes(ClassReminderNotification::class, 2);
        $this->assertNotNull($lesson->fresh()->reminder_24h_sent_at);
    }

    public function test_a_second_run_does_not_resend_the_2h_reminder(): void
    {
        Notification::fake();
        $this->scheduledLesson(now()->addHours(2));

        $this->artisan('classmate:send-reminders');
        $this->artisan('classmate:send-reminders');

        Notification::assertSentTimes(ClassReminderNotification::class, 2);
    }

    public function test_a_second_run_does_not_resend_the_10m_reminder(): void
    {
        Notification::fake();
        $this->scheduledLesson(now()->addMinutes(5));

        $this->artisan('classmate:send-reminders');
        $this->artisan('classmate:send-reminders');

        Notification::assertSentTimes(ClassReminderNotification::class, 2);
    }

    public function test_the_claim_is_atomic_so_only_one_competing_run_wins(): void
    {
        // Simula el corazón de la carrera: dos ejecuciones que ya leyeron la
        // misma lección con el marcador en NULL intentan reclamarla. El UPDATE
        // condicional lo resuelve el motor; solo una puede afectar 1 fila.
        $lesson = $this->scheduledLesson(now()->addHours(24));

        $first = Lesson::whereKey($lesson->id)
            ->whereNull('reminder_24h_sent_at')
            ->where('status', 'scheduled')
            ->update(['reminder_24h_sent_at' => now()]);

        $second = Lesson::whereKey($lesson->id)
            ->whereNull('reminder_24h_sent_at')
            ->where('status', 'scheduled')
            ->update(['reminder_24h_sent_at' => now()]);

        $this->assertSame(1, $first, 'La primera ejecución debe reclamar la lección.');
        $this->assertSame(0, $second, 'La segunda no debe poder reclamarla otra vez.');
    }

    public function test_a_lesson_cancelled_between_select_and_claim_is_not_notified(): void
    {
        Notification::fake();
        $lesson = $this->scheduledLesson(now()->addHours(24));
        $lesson->update(['status' => 'cancelled']);

        $this->artisan('classmate:send-reminders');

        Notification::assertNothingSent();
    }

    // ── F-10: destinatario correcto del aviso de solicitud sin responder ──

    public function test_an_open_request_alerts_every_verified_teacher_of_the_subject(): void
    {
        Notification::fake();
        $subject = $this->subject();
        $teacherA = $this->verifiedTeacher($subject);
        $teacherB = $this->verifiedTeacher($subject);
        $this->openRequest($subject, hoursOld: 13);

        $this->artisan('classmate:send-reminders');

        // Antes NO se avisaba a nadie en este caso —el flujo mayoritario— y la
        // solicitud quedaba marcada como avisada para siempre.
        Notification::assertSentTo($teacherA, UnansweredRequestNotification::class);
        Notification::assertSentTo($teacherB, UnansweredRequestNotification::class);
    }

    public function test_a_referral_bound_request_alerts_only_that_teacher(): void
    {
        Notification::fake();
        $subject = $this->subject();
        $bound = $this->verifiedTeacher($subject);
        $other = $this->verifiedTeacher($subject);

        $request = $this->openRequest($subject, hoursOld: 13);
        $request->teacher_profile_id = $bound->teacherProfile->id;
        $request->save();

        $this->artisan('classmate:send-reminders');

        Notification::assertSentTo($bound, UnansweredRequestNotification::class);
        Notification::assertNotSentTo($other, UnansweredRequestNotification::class);
    }

    public function test_an_offer_bound_request_alerts_the_offer_owner(): void
    {
        Notification::fake();
        $subject = $this->subject();
        $owner = $this->verifiedTeacher($subject);
        $other = $this->verifiedTeacher($subject);

        $offer = ClassOffer::create([
            'teacher_profile_id' => $owner->teacherProfile->id,
            'subject_id'         => $subject->id,
            'title'              => 'Oferta de prueba',
            'description'        => 'Descripción de prueba para la oferta.',
            'price_per_hour'     => '20.00',
            'is_active'          => true,
        ]);

        $request = $this->openRequest($subject, hoursOld: 13);
        $request->update(['class_offer_id' => $offer->id]);

        $this->artisan('classmate:send-reminders');

        Notification::assertSentTo($owner, UnansweredRequestNotification::class);
        Notification::assertNotSentTo($other, UnansweredRequestNotification::class);
    }

    public function test_a_request_without_any_eligible_teacher_is_not_marked_as_alerted(): void
    {
        Notification::fake();
        $subject = $this->subject();
        // Profesor NO verificado: no es destinatario elegible todavía.
        $this->verifiedTeacher($subject)->teacherProfile->update(['is_verified' => false]);
        $request = $this->openRequest($subject, hoursOld: 13);

        $this->artisan('classmate:send-reminders');

        Notification::assertNothingSent();
        $this->assertNull(
            $request->fresh()->request_reminder_sent_at,
            'Sin destinatario, la solicitud debe seguir siendo candidata: un profesor puede verificarse más tarde.'
        );
    }

    public function test_a_request_alerted_once_is_not_alerted_again(): void
    {
        Notification::fake();
        $subject = $this->subject();
        $this->verifiedTeacher($subject);
        $this->openRequest($subject, hoursOld: 13);

        $this->artisan('classmate:send-reminders');
        $this->artisan('classmate:send-reminders');

        Notification::assertSentTimes(UnansweredRequestNotification::class, 1);
    }

    public function test_a_recent_request_is_not_alerted_yet(): void
    {
        Notification::fake();
        $subject = $this->subject();
        $this->verifiedTeacher($subject);
        $this->openRequest($subject, hoursOld: 3);

        $this->artisan('classmate:send-reminders');

        Notification::assertNothingSent();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

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

    private function verifiedTeacher(Subject $subject): User
    {
        $teacher = $this->userWithRole('teacher');
        $profile = TeacherProfile::create([
            'user_id'           => $teacher->id,
            'is_verified'       => true,
            'credits_available' => 0,
            'credits_reserved'  => 0,
        ]);
        $profile->subjects()->attach($subject->id);

        return $teacher->fresh('teacherProfile');
    }

    private function openRequest(Subject $subject, int $hoursOld): ClassRequest
    {
        $parent  = $this->userWithRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name'     => 'Alumno',
            'last_name'      => 'Prueba',
            'grade_level'    => 'secundaria',
        ]);

        $request = ClassRequest::create([
            'student_id'  => $student->id,
            'subject_id'  => $subject->id,
            'help_needed' => 'Necesita reforzar el tema.',
            'status'      => 'open',
        ]);

        $request->created_at = now()->subHours($hoursOld);
        $request->save();

        return $request;
    }

    private function scheduledLesson(Carbon $startTime): Lesson
    {
        $subject = $this->subject();
        $teacher = $this->verifiedTeacher($subject);
        $parent  = $this->userWithRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name'     => 'Alumno',
            'last_name'      => 'Prueba',
            'grade_level'    => 'secundaria',
        ]);

        return Lesson::create([
            'teacher_profile_id' => $teacher->teacherProfile->id,
            'student_id'         => $student->id,
            'start_time'         => $startTime,
            'duration_minutes'   => 60,
            'status'             => 'scheduled',
        ]);
    }
}
