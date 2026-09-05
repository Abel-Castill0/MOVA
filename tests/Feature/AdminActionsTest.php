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
use App\Notifications\ClassCancelledNotification;
use App\Notifications\TeacherRejectedNotification;
use App\Notifications\TeacherVerifiedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * §15 — Contratos peligrosos del panel de administración.
 *
 * La auditoría encontró la cobertura muy desbalanceada: ~230 tests en pagos y
 * ~106 en créditos, frente a UN solo test de exposición de datos para todo el
 * panel de admin — pese a que ese panel puede consumir o liberar créditos
 * (force-complete / force-refund) y desactivar cuentas
 * (docs/MOVA_SYSTEM_MAP.md §30.3, R-20).
 *
 * No se persigue subir un número de tests: se cubren las acciones que MUEVEN
 * DINERO o CIERRAN ACCESOS, y sus estados inválidos.
 */
class AdminActionsTest extends TestCase
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
        Notification::fake();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function teacher(bool $verified = false, int $available = 10, int $reserved = 0): array
    {
        $user = User::factory()->create();
        $user->assignRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $user->id,
            'hourly_rate' => 20,
            'bio' => 'Profesor con experiencia.',
            'is_verified' => $verified,
            'credits_available' => $available,
            'credits_reserved' => $reserved,
        ]);
        $profile->subjects()->sync([$this->subject->id => ['specific_rate' => null]]);

        return [$user, $profile];
    }

    /**
     * Clase en un estado dado, con su asiento de reserva en el ledger — sin él,
     * `reservedCreditAmount()` lanza y las acciones financieras fallan.
     */
    private function lesson(string $status, TeacherProfile $profile, int $credits = 1): array
    {
        $parent = User::factory()->create();
        $parent->assignRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'grade_level' => 'secundaria',
        ]);

        $request = ClassRequest::create([
            'student_id' => $student->id,
            'subject_id' => $this->subject->id,
            'help_needed' => 'Apoyo con álgebra.',
            'status' => 'accepted',
        ]);

        $lesson = Lesson::create([
            'teacher_profile_id' => $profile->id,
            'student_id' => $student->id,
            'class_request_id' => $request->id,
            'start_time' => now()->addDay(),
            'duration_minutes' => 60,
            'status' => $status,
        ]);

        CreditTransaction::create([
            'teacher_profile_id' => $profile->id,
            'lesson_id' => $lesson->id,
            'idempotency_key' => "lesson:{$lesson->id}:reservation",
            'type' => 'reservation',
            'amount' => $credits,
            'description' => 'Reserva por aceptación de clase',
        ]);

        return [$parent, $lesson];
    }

    // ═══════════════ Verificación de profesores ═══════════════

    public function test_an_admin_verifies_a_teacher_and_the_teacher_is_notified(): void
    {
        [$teacher, $profile] = $this->teacher(verified: false);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.teachers.verify', $profile))
            ->assertRedirect();

        $fresh = $profile->fresh();
        $this->assertTrue((bool) $fresh->is_verified);
        $this->assertSame($admin->id, $fresh->reviewed_by);
        $this->assertNotNull($fresh->reviewed_at);

        Notification::assertSentTo($teacher, TeacherVerifiedNotification::class);
    }

    public function test_verifying_clears_a_previous_rejection(): void
    {
        [, $profile] = $this->teacher(verified: false);
        $profile->update(['rejected_at' => now(), 'rejection_reason' => 'Faltaban datos']);

        $this->actingAs($this->admin())->post(route('admin.teachers.verify', $profile));

        $fresh = $profile->fresh();
        $this->assertNull($fresh->rejected_at, 'Un perfil verificado no puede seguir marcado como rechazado.');
        $this->assertNull($fresh->rejection_reason);
    }

    public function test_rejecting_a_teacher_deactivates_all_their_offers(): void
    {
        [$teacher, $profile] = $this->teacher(verified: true);
        $offer = $profile->classOffers()->create([
            'subject_id' => $this->subject->id,
            'title' => 'Clases de álgebra',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.teachers.reject', $profile), [
                'reason' => 'El perfil no acredita la formación declarada.',
            ])
            ->assertRedirect();

        $this->assertFalse((bool) $profile->fresh()->is_verified);
        $this->assertNotNull($profile->fresh()->rejected_at);
        $this->assertFalse((bool) $offer->fresh()->is_active, 'Un profesor rechazado no puede seguir ofertando.');

        Notification::assertSentTo($teacher, TeacherRejectedNotification::class);
    }

    public function test_rejecting_a_teacher_requires_a_substantive_reason(): void
    {
        [, $profile] = $this->teacher(verified: true);

        $this->actingAs($this->admin())
            ->post(route('admin.teachers.reject', $profile), ['reason' => 'no'])
            ->assertSessionHasErrors('reason');

        $this->assertTrue((bool) $profile->fresh()->is_verified, 'Sin motivo válido no se rechaza.');
    }

    /**
     * Un profesor rechazado no puede aceptar solicitudes. Es la consecuencia que
     * de verdad importa del rechazo.
     */
    public function test_a_rejected_teacher_can_no_longer_accept_requests(): void
    {
        [$teacher, $profile] = $this->teacher(verified: true);

        $this->actingAs($this->admin())->post(route('admin.teachers.reject', $profile), [
            'reason' => 'El perfil no acredita la formación declarada.',
        ]);

        $parent = User::factory()->create();
        $parent->assignRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Luis', 'last_name' => 'Gómez', 'grade_level' => 'primaria',
        ]);
        $request = ClassRequest::create([
            'student_id' => $student->id,
            'subject_id' => $this->subject->id,
            'help_needed' => 'Apoyo con sumas.',
            'status' => 'open',
        ]);

        $this->actingAs($teacher->fresh())
            ->post(route('lessons.store'), [
                'class_request_id' => $request->id,
                'start_time' => now()->addDays(2)->toIso8601String(),
                'duration_minutes' => 60,
            ])
            ->assertForbidden();
    }

    // ═══════════════ Suspensión ═══════════════

    public function test_suspending_a_user_locks_them_out_of_the_product(): void
    {
        [$teacher] = $this->teacher(verified: true);

        $this->actingAs($this->admin())
            ->post(route('admin.users.suspend', $teacher), ['reason' => 'Conducta inapropiada'])
            ->assertRedirect();

        $this->assertNotNull($teacher->fresh()->suspended_at);

        // La consecuencia real: no puede operar.
        $this->actingAs($teacher->fresh())
            ->get(route('teacher.requests'))
            ->assertRedirect(route('suspended'));
    }

    public function test_unsuspending_restores_access(): void
    {
        [$teacher] = $this->teacher(verified: true);
        $teacher->update(['suspended_at' => now(), 'suspension_reason' => 'Revisión']);

        $this->actingAs($this->admin())
            ->post(route('admin.users.unsuspend', $teacher))
            ->assertRedirect();

        $fresh = $teacher->fresh();
        $this->assertNull($fresh->suspended_at);
        $this->assertNull($fresh->suspension_reason);

        $this->actingAs($fresh)->get(route('teacher.requests'))->assertOk();
    }

    /**
     * Invariante deliberada: un admin no puede suspender a otro admin. Sin esto,
     * dos administradores podrían dejarse mutuamente fuera y la plataforma
     * quedarse sin nadie que pueda revertirlo.
     */
    public function test_an_admin_cannot_suspend_another_admin(): void
    {
        $target = $this->admin();

        $this->actingAs($this->admin())
            ->post(route('admin.users.suspend', $target), ['reason' => 'Conflicto interno'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($target->fresh()->suspended_at);
    }

    /**
     * Y el complemento: un admin suspendido conserva acceso al panel, porque el
     * grupo de rutas de admin NO lleva `not.suspended` a propósito.
     */
    public function test_a_suspended_admin_keeps_access_to_the_admin_panel(): void
    {
        $admin = $this->admin();
        $admin->update(['suspended_at' => now(), 'suspension_reason' => 'Error']);

        $this->actingAs($admin->fresh())
            ->get(route('admin.users'))
            ->assertOk();
    }

    // ═══════════════ Acciones financieras sobre clases ═══════════════

    public function test_cancelling_a_scheduled_lesson_returns_the_reserved_credits(): void
    {
        [$teacher, $profile] = $this->teacher(verified: true, available: 9, reserved: 1);
        [$parent, $lesson] = $this->lesson('scheduled', $profile);

        $this->actingAs($this->admin())
            ->post(route('admin.lessons.cancel', $lesson), ['reason' => 'El profesor no se presentó.'])
            ->assertRedirect();

        $this->assertSame('cancelled', $lesson->fresh()->status);
        $fresh = $profile->fresh();
        $this->assertSame(10, $fresh->credits_available, 'El crédito vuelve a estar disponible.');
        $this->assertSame(0, $fresh->credits_reserved);

        $this->assertDatabaseHas('credit_transactions', [
            'idempotency_key' => "lesson:{$lesson->id}:release",
            'type' => 'refund',
        ]);

        Notification::assertSentTo($teacher, ClassCancelledNotification::class);
        Notification::assertSentTo($parent, ClassCancelledNotification::class);
    }

    public function test_force_completing_consumes_the_reserved_credit(): void
    {
        [, $profile] = $this->teacher(verified: true, available: 9, reserved: 1);
        [, $lesson] = $this->lesson('needs_admin_review', $profile);

        $this->actingAs($this->admin())
            ->post(route('admin.lessons.force-complete', $lesson), [
                'reason' => 'La clase se dictó; el padre nunca confirmó.',
            ])
            ->assertRedirect();

        $this->assertSame('completed', $lesson->fresh()->status);
        $this->assertNotNull($lesson->fresh()->credits_settled_at);

        $fresh = $profile->fresh();
        $this->assertSame(0, $fresh->credits_reserved, 'La reserva se consume.');
        $this->assertSame(9, $fresh->credits_available, 'El disponible NO cambia al consumir.');
        $this->assertSame(1, $fresh->completed_classes_count);

        $this->assertDatabaseHas('credit_transactions', [
            'idempotency_key' => "lesson:{$lesson->id}:consumption",
            'type' => 'consumption',
        ]);
    }

    public function test_force_refunding_releases_the_reserved_credit(): void
    {
        [, $profile] = $this->teacher(verified: true, available: 9, reserved: 1);
        [, $lesson] = $this->lesson('needs_admin_review', $profile);

        $this->actingAs($this->admin())
            ->post(route('admin.lessons.force-refund', $lesson), [
                'reason' => 'La clase nunca se dictó.',
            ])
            ->assertRedirect();

        $this->assertSame('cancelled', $lesson->fresh()->status);
        $fresh = $profile->fresh();
        $this->assertSame(10, $fresh->credits_available);
        $this->assertSame(0, $fresh->credits_reserved);
        $this->assertSame(0, $fresh->completed_classes_count, 'Una devolución no cuenta como clase dictada.');
    }

    /**
     * Doble clic sobre "forzar cierre": el crédito no puede consumirse dos
     * veces. La garantía es `credits_settled_at` más el UNIQUE de
     * `idempotency_key`.
     */
    public function test_force_completing_twice_never_double_consumes(): void
    {
        [, $profile] = $this->teacher(verified: true, available: 9, reserved: 1);
        [, $lesson] = $this->lesson('needs_admin_review', $profile);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.lessons.force-complete', $lesson), ['reason' => 'Primera vez.']);
        $this->actingAs($admin)->post(route('admin.lessons.force-complete', $lesson->fresh()), ['reason' => 'Segunda vez.']);

        $this->assertSame(
            1,
            CreditTransaction::where('lesson_id', $lesson->id)->where('type', 'consumption')->count()
        );
        $this->assertSame(0, $profile->fresh()->credits_reserved);
    }

    // ── Estados inválidos ────────────────────────────────────────────────

    /** @dataProvider nonCancellableStatuses */
    public function test_only_scheduled_lessons_can_be_cancelled_by_an_admin(string $status): void
    {
        [, $profile] = $this->teacher(verified: true, available: 9, reserved: 1);
        [, $lesson] = $this->lesson($status, $profile);

        $this->actingAs($this->admin())
            ->post(route('admin.lessons.cancel', $lesson), ['reason' => 'Intento inválido.'])
            ->assertStatus(422);

        $this->assertSame($status, $lesson->fresh()->status);
        $this->assertSame(1, $profile->fresh()->credits_reserved, 'No se mueve ni un crédito.');
    }

    public static function nonCancellableStatuses(): array
    {
        return [
            'pagada' => ['paid'],
            'esperando calificación' => ['pending_parent_confirmation'],
            'en revisión' => ['needs_admin_review'],
        ];
    }

    public function test_a_scheduled_lesson_cannot_be_force_completed(): void
    {
        [, $profile] = $this->teacher(verified: true, available: 9, reserved: 1);
        [, $lesson] = $this->lesson('scheduled', $profile);

        $this->actingAs($this->admin())
            ->post(route('admin.lessons.force-complete', $lesson), [
                'reason' => 'Todavía no ha ocurrido.',
            ])
            ->assertStatus(422);

        $this->assertSame('scheduled', $lesson->fresh()->status);
        $this->assertSame(1, $profile->fresh()->credits_reserved);
    }

    public function test_financial_admin_actions_require_a_reason(): void
    {
        [, $profile] = $this->teacher(verified: true, available: 9, reserved: 1);
        [, $lesson] = $this->lesson('needs_admin_review', $profile);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.lessons.force-complete', $lesson), [])
            ->assertSessionHasErrors('reason');

        $this->actingAs($admin)
            ->post(route('admin.lessons.force-refund', $lesson), [])
            ->assertSessionHasErrors('reason');

        $this->assertSame('needs_admin_review', $lesson->fresh()->status);
    }

    /**
     * Una clase con el ledger roto no se cierra "a ojo": el sistema prefiere
     * fallar a fabricar un importe (CLAUDE.md §2).
     */
    public function test_a_lesson_with_a_broken_ledger_cannot_be_cancelled_silently(): void
    {
        [, $profile] = $this->teacher(verified: true, available: 9, reserved: 1);
        [, $lesson] = $this->lesson('scheduled', $profile);

        // Se destruye la reserva: ahora hay 0 asientos donde debía haber 1.
        CreditTransaction::where('lesson_id', $lesson->id)->delete();

        $this->actingAs($this->admin())
            ->post(route('admin.lessons.cancel', $lesson), ['reason' => 'Cancelación normal.'])
            ->assertStatus(422);

        $this->assertSame('scheduled', $lesson->fresh()->status);
    }

    // ═══════════════ Autorización ═══════════════

    /** @dataProvider adminRoutes */
    public function test_a_teacher_cannot_reach_admin_actions(string $routeName, string $method): void
    {
        [$teacher, $profile] = $this->teacher(verified: true, available: 9, reserved: 1);
        [, $lesson] = $this->lesson('needs_admin_review', $profile);

        $target = str_contains($routeName, 'teachers.') || str_contains($routeName, 'users.')
            ? $profile->id
            : $lesson->id;

        $this->actingAs($teacher)
            ->call($method, route($routeName, $target), ['reason' => 'Intento indebido de acceso.'])
            ->assertForbidden();
    }

    /** @dataProvider adminRoutes */
    public function test_a_parent_cannot_reach_admin_actions(string $routeName, string $method): void
    {
        [, $profile] = $this->teacher(verified: true, available: 9, reserved: 1);
        [$parent, $lesson] = $this->lesson('needs_admin_review', $profile);

        $target = str_contains($routeName, 'teachers.') || str_contains($routeName, 'users.')
            ? $profile->id
            : $lesson->id;

        $this->actingAs($parent)
            ->call($method, route($routeName, $target), ['reason' => 'Intento indebido de acceso.'])
            ->assertForbidden();
    }

    public static function adminRoutes(): array
    {
        return [
            'verificar profesor' => ['admin.teachers.verify', 'POST'],
            'rechazar profesor' => ['admin.teachers.reject', 'POST'],
            'forzar cierre de clase' => ['admin.lessons.force-complete', 'POST'],
            'forzar devolución de clase' => ['admin.lessons.force-refund', 'POST'],
            'cancelar clase' => ['admin.lessons.cancel', 'POST'],
        ];
    }

    /** @dataProvider adminPages */
    public function test_admin_pages_are_closed_to_non_admins(string $routeName): void
    {
        [$teacher] = $this->teacher(verified: true);

        $this->actingAs($teacher)->get(route($routeName))->assertForbidden();

        $parent = User::factory()->create();
        $parent->assignRole('parent');
        $this->actingAs($parent)->get(route($routeName))->assertForbidden();
    }

    public static function adminPages(): array
    {
        return [
            ['admin.users'],
            ['admin.teachers.pending'],
            ['admin.requests'],
            ['admin.lessons'],
            ['admin.recharges.index'],
            ['admin.reviews'],
            ['admin.ai-usage'],
        ];
    }

    public function test_a_guest_cannot_reach_the_admin_panel(): void
    {
        $this->get(route('admin.users'))->assertRedirect(route('login'));
    }

    // ═══════════════ Auditoría ═══════════════

    public function test_admin_actions_on_lessons_leave_an_audit_trail(): void
    {
        [, $profile] = $this->teacher(verified: true, available: 9, reserved: 1);
        [, $lesson] = $this->lesson('needs_admin_review', $profile);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.lessons.force-complete', $lesson), [
            'reason' => 'La clase se dictó; el padre nunca confirmó.',
        ]);

        $event = ClassEvent::where('lesson_id', $lesson->id)
            ->where('event_type', 'class_force_completed')
            ->first();

        $this->assertNotNull($event, 'Una acción de admin que mueve dinero debe quedar registrada.');
        $this->assertSame($admin->id, $event->actor_id);
        $this->assertStringContainsString('nunca confirmó', $event->reason);
    }

    // ═══════════════ Moderación de reseñas ═══════════════

    public function test_hiding_a_review_removes_it_from_the_public_rating(): void
    {
        [, $profile] = $this->teacher(verified: true, available: 9, reserved: 1);
        [$parent, $lesson] = $this->lesson('completed', $profile);

        LessonReport::create([
            'lesson_id' => $lesson->id,
            'teacher_profile_id' => $profile->id,
            'student_id' => $lesson->student_id,
            'topic_covered' => 'Álgebra',
            'student_performance' => 'Bien',
            'sent_to_parent_at' => now(),
        ]);

        $review = \App\Models\TeacherReview::create([
            'lesson_id' => $lesson->id,
            'teacher_profile_id' => $profile->id,
            'parent_id' => $parent->id,
            'student_id' => $lesson->student_id,
            'rating' => 5,
            'comment' => 'Excelente',
            'is_visible' => true,
        ]);

        $this->assertSame(5.0, $profile->fresh()->avgRating());

        $this->actingAs($this->admin())
            ->post(route('admin.reviews.hide', $review), ['reason' => 'Contenido inapropiado'])
            ->assertRedirect();

        $this->assertFalse((bool) $review->fresh()->is_visible);
        $this->assertNull($profile->fresh()->avgRating(), 'Una reseña oculta no cuenta para el rating público.');
    }

    public function test_a_teacher_cannot_moderate_reviews_about_themselves(): void
    {
        [$teacher, $profile] = $this->teacher(verified: true, available: 9, reserved: 1);
        [$parent, $lesson] = $this->lesson('completed', $profile);

        $review = \App\Models\TeacherReview::create([
            'lesson_id' => $lesson->id,
            'teacher_profile_id' => $profile->id,
            'parent_id' => $parent->id,
            'student_id' => $lesson->student_id,
            'rating' => 1,
            'comment' => 'No me gustó',
            'is_visible' => true,
        ]);

        $this->actingAs($teacher)
            ->post(route('admin.reviews.hide', $review), ['reason' => 'Injusta'])
            ->assertForbidden();

        $this->assertTrue((bool) $review->fresh()->is_visible);
    }
}
