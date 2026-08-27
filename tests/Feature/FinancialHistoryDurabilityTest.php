<?php

namespace Tests\Feature;

use App\Models\ClassRequest;
use App\Models\CreditTransaction;
use App\Models\Lesson;
use App\Models\RechargeRequest;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * F-01 / GAP-04 — Durabilidad del historial financiero frente al borrado de
 * cuentas.
 *
 * Contexto honesto: la auditoría de Fase 2 reportó esto como CRITICAL leyendo
 * solo las migraciones CREATE TABLE, donde las FK son cascadeOnDelete(). Era
 * incorrecto: la migración 2026_07_10_000002_protect_monetization_history las
 * convierte a RESTRICT, y el esquema MySQL real lo confirma.
 *
 * El hueco REAL que queda, y que estos tests cubren: esa migración hace
 * early-return en drivers que no son MySQL, y la suite corre sobre SQLite. Es
 * decir, la protección de producción no era observable por ningún test, y en
 * el entorno de pruebas un $user->delete() sí arrasaba el ledger. El guard de
 * User::booted() hace la protección independiente del motor y verificable.
 */
class FinancialHistoryDurabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    // ── El ledger sobrevive al intento de borrado ────────────────────────

    public function test_deleting_a_teacher_with_ledger_entries_is_refused(): void
    {
        [$teacher, $profile] = $this->teacher();
        CreditTransaction::create([
            'teacher_profile_id' => $profile->id,
            'idempotency_key'    => "teacher:{$profile->id}:welcome",
            'type'               => 'deposit',
            'amount'             => 5,
            'description'        => 'Bono de bienvenida',
        ]);

        try {
            $teacher->delete();
            $this->fail('Se esperaba que el borrado fuera rechazado.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('historial financiero', $e->getMessage());
        }

        $this->assertDatabaseHas('users', ['id' => $teacher->id]);
        $this->assertSame(1, CreditTransaction::where('teacher_profile_id', $profile->id)->count());
    }

    public function test_deleting_a_teacher_with_recharge_history_is_refused(): void
    {
        [$teacher, $profile] = $this->teacher();
        RechargeRequest::create([
            'teacher_profile_id' => $profile->id,
            'package_code'       => 'inicio',
            'package_name'       => 'Inicio',
            'credits'            => 5,
            'amount_pen'         => '10.00',
            'payment_method'     => 'yape',
            'operation_number'   => '123456789',
            'status'             => 'approved',
        ]);

        $this->expectException(RuntimeException::class);
        $teacher->delete();
    }

    public function test_deleting_a_teacher_with_lessons_is_refused(): void
    {
        [$teacher, $profile, $subject] = $this->teacher();
        $student = $this->student();

        Lesson::create([
            'teacher_profile_id' => $profile->id,
            'student_id'         => $student->id,
            'start_time'         => now()->subDays(3),
            'duration_minutes'   => 60,
            'status'             => 'completed',
        ]);

        $this->expectException(RuntimeException::class);
        $teacher->delete();
    }

    public function test_deleting_a_parent_whose_student_has_lessons_is_refused(): void
    {
        [, $profile, $subject] = $this->teacher();
        $student = $this->student();
        $parent  = $student->parent;

        Lesson::create([
            'teacher_profile_id' => $profile->id,
            'student_id'         => $student->id,
            'start_time'         => now()->subDays(3),
            'duration_minutes'   => 60,
            'status'             => 'completed',
        ]);

        $this->expectException(RuntimeException::class);
        $parent->delete();
    }

    public function test_deleting_a_parent_whose_student_has_class_requests_is_refused(): void
    {
        [, , $subject] = $this->teacher();
        $student = $this->student();

        ClassRequest::create([
            'student_id'  => $student->id,
            'subject_id'  => $subject->id,
            'help_needed' => 'Necesita reforzar el tema.',
            'status'      => 'open',
        ]);

        $this->expectException(RuntimeException::class);
        $student->parent->delete();
    }

    // ── Sin historial, el borrado sigue permitido ────────────────────────

    public function test_a_user_without_any_history_can_still_be_deleted(): void
    {
        // La protección debe morder solo cuando hay algo que preservar; si no,
        // convertiría el derecho de baja en algo imposible de ejercer.
        $user = $this->userWithRole('parent');

        $user->delete();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_a_teacher_profile_without_ledger_can_still_be_deleted(): void
    {
        [$teacher] = $this->teacher();

        $teacher->delete();

        $this->assertDatabaseMissing('users', ['id' => $teacher->id]);
    }

    // ── El camino correcto: anonimizar, no borrar ────────────────────────

    public function test_the_profile_deletion_endpoint_anonymizes_instead_of_destroying_history(): void
    {
        [$teacher, $profile] = $this->teacher();
        CreditTransaction::create([
            'teacher_profile_id' => $profile->id,
            'idempotency_key'    => "teacher:{$profile->id}:welcome",
            'type'               => 'deposit',
            'amount'             => 5,
            'description'        => 'Bono de bienvenida',
        ]);

        $this->actingAs($teacher)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect('/');

        // La cuenta sigue existiendo pero anonimizada, y el ledger intacto.
        $teacher->refresh();
        $this->assertSame('Cuenta eliminada', $teacher->name);
        $this->assertSame("deleted-{$teacher->id}@mova.invalid", $teacher->email);
        $this->assertNotNull($teacher->suspended_at);
        $this->assertSame(1, CreditTransaction::where('teacher_profile_id', $profile->id)->count());
    }

    public function test_has_protected_history_is_the_single_source_of_truth(): void
    {
        [$teacher, $profile] = $this->teacher();
        $this->assertFalse($teacher->hasProtectedHistory());

        CreditTransaction::create([
            'teacher_profile_id' => $profile->id,
            'idempotency_key'    => "teacher:{$profile->id}:welcome",
            'type'               => 'deposit',
            'amount'             => 5,
            'description'        => 'Bono de bienvenida',
        ]);

        $this->assertTrue($teacher->fresh()->hasProtectedHistory());
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['password' => 'password']);
        $user->assignRole($role);

        return $user;
    }

    /** @return array{0: User, 1: TeacherProfile, 2: Subject} */
    private function teacher(): array
    {
        $teacher = $this->userWithRole('teacher');
        $profile = TeacherProfile::create([
            'user_id'           => $teacher->id,
            'is_verified'       => true,
            'credits_available' => 0,
            'credits_reserved'  => 0,
        ]);
        $subject = Subject::create([
            'name'  => 'Materia '.fake()->unique()->numerify('####'),
            'level' => 'secundaria',
        ]);
        $profile->subjects()->attach($subject->id);

        return [$teacher->fresh(), $profile, $subject];
    }

    private function student(): Student
    {
        $parent = $this->userWithRole('parent');

        return Student::create([
            'parent_user_id' => $parent->id,
            'first_name'     => 'Alumno',
            'last_name'      => 'Prueba',
            'grade_level'    => 'secundaria',
        ]);
    }
}
