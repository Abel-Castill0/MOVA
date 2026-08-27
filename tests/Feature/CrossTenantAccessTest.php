<?php

namespace Tests\Feature;

use App\Events\ClassConfirmed;
use App\Models\ClassRequest;
use App\Models\CreditTransaction;
use App\Models\Lesson;
use App\Models\RechargeRequest;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * GAP-02 — Matriz IDOR ejercitada endpoint por endpoint.
 *
 * La auditoría de Fase 2 revisó las 7 Policies por LECTURA y no encontró
 * huecos, pero eso no es prueba: el hallazgo crítico del 2026-08-22
 * (ClassRequestPolicy::accept() no comprobaba is_verified, permitiendo que un
 * profesor sin verificar quedara a solas con un menor) también habría pasado
 * una lectura superficial. Aquí se ejercitan los accesos cruzados reales.
 *
 * Actores: teacherA / teacherB / parentA / parentB / admin / suspendido.
 */
class CrossTenantAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $teacherA;
    private User $teacherB;
    private User $parentA;
    private User $parentB;
    private User $admin;
    private Lesson $lessonA;
    private ClassRequest $requestA;
    private RechargeRequest $rechargeA;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        Notification::fake();
        // Event::fake() SIN argumentos también silencia los eventos de modelo
        // de Eloquent, y Subject::booted() calcula normalized_name ahí — se
        // acota a los eventos de dominio, igual que MonetizationIntegrityTest.
        Event::fake([ClassConfirmed::class]);

        $subject = Subject::create(['name' => 'Materia IDOR', 'level' => 'secundaria']);

        [$this->teacherA, $profileA] = $this->teacher($subject);
        [$this->teacherB] = $this->teacher($subject);
        $this->admin = $this->userWithRole('admin');

        [$this->parentA, $studentA] = $this->parentWithStudent();
        [$this->parentB] = $this->parentWithStudent();

        $this->requestA = ClassRequest::create([
            'student_id'  => $studentA->id,
            'subject_id'  => $subject->id,
            'help_needed' => 'Necesita reforzar el tema.',
            'status'      => 'open',
        ]);

        $this->lessonA = Lesson::create([
            'teacher_profile_id' => $profileA->id,
            'student_id'         => $studentA->id,
            'class_request_id'   => $this->requestA->id,
            'start_time'         => now()->addMinutes(10),
            'duration_minutes'   => 60,
            'status'             => 'scheduled',
            'jitsi_room'         => 'mova-lesson-idor-test',
        ]);

        CreditTransaction::create([
            'teacher_profile_id' => $profileA->id,
            'lesson_id'          => $this->lessonA->id,
            'idempotency_key'    => "lesson:{$this->lessonA->id}:reservation",
            'type'               => 'reservation',
            'amount'             => 1,
            'description'        => 'Reserva por aceptación de clase',
        ]);

        $this->rechargeA = RechargeRequest::create([
            'teacher_profile_id' => $profileA->id,
            'package_code'       => 'inicio',
            'package_name'       => 'Inicio',
            'credits'            => 5,
            'amount_pen'         => '10.00',
            'payment_method'     => 'yape',
            'operation_number'   => '999888777',
            'status'             => 'pending',
        ]);
    }

    // ── Sala de vídeo: el activo más sensible (menores) ──────────────────

    public function test_a_stranger_cannot_join_another_familys_lesson_room(): void
    {
        foreach ([$this->teacherB, $this->parentB] as $intruder) {
            $this->actingAs($intruder)
                ->get(route('lessons.join', $this->lessonA))
                ->assertForbidden();
        }
    }

    public function test_a_guest_cannot_join_any_lesson_room(): void
    {
        $this->get(route('lessons.join', $this->lessonA))->assertRedirect(route('login'));
    }

    // ── Mutaciones sobre la clase ajena ──────────────────────────────────

    public function test_a_stranger_cannot_cancel_another_familys_lesson(): void
    {
        foreach ([$this->teacherB, $this->parentB] as $intruder) {
            $this->actingAs($intruder)
                ->post(route('lessons.cancel', $this->lessonA), ['reason' => 'intento de intrusión'])
                ->assertForbidden();
        }

        $this->assertSame('scheduled', $this->lessonA->fresh()->status);
    }

    public function test_a_stranger_cannot_reschedule_another_familys_lesson(): void
    {
        $original = $this->lessonA->start_time;

        $this->actingAs($this->teacherB)
            ->post(route('lessons.reschedule', $this->lessonA), [
                'start_time' => now()->addDays(3)->toIso8601String(),
            ])
            ->assertForbidden();

        $this->assertEquals($original, $this->lessonA->fresh()->start_time);
    }

    public function test_only_the_owning_parent_can_confirm_payment(): void
    {
        // Ni el profesor de la propia clase ni un admin: confirmPayment es
        // exclusiva del padre (declara haber pagado). Ver F-11.
        foreach ([$this->teacherA, $this->teacherB, $this->parentB, $this->admin] as $intruder) {
            $this->actingAs($intruder)
                ->post(route('lessons.confirm-payment', $this->lessonA))
                ->assertForbidden();
        }
    }

    public function test_a_teacher_cannot_report_on_another_teachers_lesson(): void
    {
        $this->lessonA->update(['status' => 'paid']);

        $this->actingAs($this->teacherB)
            ->post(route('lesson-reports.store', $this->lessonA), [
                'topic_covered'       => 'Intento de intrusión',
                'student_performance' => 'Texto arbitrario de prueba.',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('lesson_reports', 0);
    }

    // ── Recargas: dinero ─────────────────────────────────────────────────

    public function test_a_teacher_cannot_approve_their_own_recharge(): void
    {
        // El escenario más obvio de auto-servicio financiero.
        $this->actingAs($this->teacherA)
            ->post(route('admin.recharges.approve', $this->rechargeA))
            ->assertForbidden();

        $this->assertSame('pending', $this->rechargeA->fresh()->status);
        $this->assertSame(0, $this->teacherA->teacherProfile->fresh()->credits_available);
    }

    public function test_a_parent_cannot_approve_any_recharge(): void
    {
        $this->actingAs($this->parentA)
            ->post(route('admin.recharges.approve', $this->rechargeA))
            ->assertForbidden();

        $this->assertSame('pending', $this->rechargeA->fresh()->status);
    }

    public function test_a_teacher_cannot_reach_the_admin_recharge_list(): void
    {
        $this->actingAs($this->teacherA)->get(route('admin.recharges.index'))->assertForbidden();
        $this->actingAs($this->parentA)->get(route('admin.recharges.index'))->assertForbidden();
    }

    // ── Acciones administrativas destructivas ────────────────────────────

    public function test_non_admins_cannot_force_settle_a_lesson(): void
    {
        foreach ([$this->teacherA, $this->parentA] as $intruder) {
            $this->actingAs($intruder)
                ->post(route('admin.lessons.force-complete', $this->lessonA), ['reason' => 'intrusión'])
                ->assertForbidden();

            $this->actingAs($intruder)
                ->post(route('admin.lessons.force-refund', $this->lessonA), ['reason' => 'intrusión'])
                ->assertForbidden();
        }

        $this->assertSame('scheduled', $this->lessonA->fresh()->status);
        $this->assertNull($this->lessonA->fresh()->credits_settled_at);
    }

    public function test_non_admins_cannot_verify_or_suspend_users(): void
    {
        $profileB = $this->teacherB->teacherProfile;

        $this->actingAs($this->teacherA)
            ->post(route('admin.teachers.verify', $profileB))->assertForbidden();

        $this->actingAs($this->parentA)
            ->post(route('admin.users.suspend', $this->teacherB), ['reason' => 'intrusión'])
            ->assertForbidden();
    }

    // ── Recursos inexistentes: no deben filtrar información ──────────────

    public function test_nonexistent_resources_return_not_found_not_a_server_error(): void
    {
        $this->actingAs($this->parentA)->get(route('lessons.join', 999999))->assertNotFound();
        $this->actingAs($this->admin)->post(route('admin.recharges.approve', 999999))->assertNotFound();
    }

    // ── Usuario suspendido ───────────────────────────────────────────────

    public function test_a_suspended_teacher_cannot_act_on_their_own_lesson(): void
    {
        $this->teacherA->update([
            'suspended_at'      => now(),
            'suspension_reason' => 'Prueba de suspensión',
        ]);

        $this->actingAs($this->teacherA)
            ->post(route('lessons.cancel', $this->lessonA), ['reason' => 'intento estando suspendido'])
            ->assertRedirect('/suspended');

        $this->assertSame('scheduled', $this->lessonA->fresh()->status);
    }

    public function test_a_suspended_admin_can_still_reach_the_admin_area(): void
    {
        // Deliberado (comentario en routes/web.php): un admin suspendido debe
        // poder revertir su propia suspensión, así que el grupo admin NO lleva
        // el middleware not.suspended. Se fija como comportamiento esperado
        // para que un cambio futuro no lo rompa en silencio.
        $this->admin->update([
            'suspended_at'      => now(),
            'suspension_reason' => 'Prueba',
        ]);

        $this->actingAs($this->admin)->get(route('admin.recharges.index'))->assertOk();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['password' => 'password']);
        $user->assignRole($role);

        return $user;
    }

    /** @return array{0: User, 1: TeacherProfile} */
    private function teacher(Subject $subject): array
    {
        $teacher = $this->userWithRole('teacher');
        $profile = TeacherProfile::create([
            'user_id'           => $teacher->id,
            'is_verified'       => true,
            'credits_available' => 0,
            'credits_reserved'  => 1,
        ]);
        $profile->subjects()->attach($subject->id);

        return [$teacher->fresh(), $profile];
    }

    /** @return array{0: User, 1: Student} */
    private function parentWithStudent(): array
    {
        $parent  = $this->userWithRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name'     => 'Alumno',
            'last_name'      => 'Prueba',
            'grade_level'    => 'secundaria',
        ]);

        return [$parent, $student];
    }
}
