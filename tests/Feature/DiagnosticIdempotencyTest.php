<?php

namespace Tests\Feature;

use App\Models\ClassRequest;
use App\Models\Student;
use App\Models\StudentDiagnostic;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * BUG-2 (docs/MOVA_AUDIT_PHASE0.md, sección Q) — DiagnosticsController::store()
 * era el único flujo de creación de datos reales sin ningún guard de
 * idempotencia: un doble-click o un reintento de red creaba dos diagnósticos
 * y dos solicitudes de clase reales, cada una notificando por separado a
 * los profesores verificados de la materia.
 */
class DiagnosticIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function payload(Student $student, Subject $subject, array $overrides = []): array
    {
        return array_merge([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'difficulty_text' => 'Necesita apoyo para entender movimiento rectilíneo uniforme.',
            'school_feedback' => 'Debe practicar ejercicios.',
            'goal' => 'prepare_exam',
            'urgency' => 'this_week',
        ], $overrides);
    }

    public function test_submitting_the_exact_same_diagnostic_twice_creates_only_one_diagnostic_and_one_class_request(): void
    {
        $parent = User::factory()->create(['email_verified_at' => now()]);
        $parent->assignRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id, 'first_name' => 'Alumno', 'last_name' => 'Doble',
            'grade_level' => 'secundaria',
        ]);
        $subject = Subject::create(['name' => 'Física', 'level' => 'secundaria']);

        $payload = $this->payload($student, $subject);

        $this->actingAs($parent)->post('/diagnostics', $payload)->assertRedirect(route('class-requests.index'));
        // Doble-click / reintento de red: mismo payload exacto, segunda request.
        $this->actingAs($parent)->post('/diagnostics', $payload)->assertRedirect(route('class-requests.index'));

        $this->assertSame(1, StudentDiagnostic::where('student_id', $student->id)->count());
        $this->assertSame(1, ClassRequest::where('student_id', $student->id)->count());
    }

    public function test_a_genuinely_different_diagnostic_for_the_same_student_still_creates_a_new_one(): void
    {
        $parent = User::factory()->create(['email_verified_at' => now()]);
        $parent->assignRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id, 'first_name' => 'Alumno', 'last_name' => 'Distinto',
            'grade_level' => 'secundaria',
        ]);
        $subject = Subject::create(['name' => 'Química', 'level' => 'secundaria']);

        $this->actingAs($parent)->post('/diagnostics', $this->payload($student, $subject))
            ->assertRedirect(route('class-requests.index'));

        // Contenido real distinto (texto de dificultad diferente) — no debe
        // bloquearse como si fuera un reintento del mismo formulario.
        $this->actingAs($parent)->post('/diagnostics', $this->payload($student, $subject, [
            'difficulty_text' => 'Ahora necesita apoyo con estequiometría, un problema distinto.',
        ]))->assertRedirect(route('class-requests.index'));

        $this->assertSame(2, StudentDiagnostic::where('student_id', $student->id)->count());
        $this->assertSame(2, ClassRequest::where('student_id', $student->id)->count());
    }

    public function test_the_class_request_created_event_fires_only_once_for_a_duplicate_submission(): void
    {
        Event::fake([\App\Events\ClassRequestCreated::class]);

        $parent = User::factory()->create(['email_verified_at' => now()]);
        $parent->assignRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id, 'first_name' => 'Alumno', 'last_name' => 'Evento',
            'grade_level' => 'secundaria',
        ]);
        $subject = Subject::create(['name' => 'Biología', 'level' => 'secundaria']);
        $payload = $this->payload($student, $subject);

        $this->actingAs($parent)->post('/diagnostics', $payload);
        $this->actingAs($parent)->post('/diagnostics', $payload);

        // Si el evento se disparara dos veces, los profesores de la materia
        // recibirían dos notificaciones idénticas por la misma necesidad real.
        Event::assertDispatchedTimes(\App\Events\ClassRequestCreated::class, 1);
    }

    public function test_idempotency_key_is_unique_at_the_database_level(): void
    {
        $parent = User::factory()->create();
        $student = Student::create([
            'parent_user_id' => $parent->id, 'first_name' => 'Alumno', 'last_name' => 'DB',
            'grade_level' => 'secundaria',
        ]);
        $subject = Subject::create(['name' => 'Historia', 'level' => 'secundaria']);

        StudentDiagnostic::create([
            'parent_user_id' => $parent->id, 'student_id' => $student->id, 'subject_id' => $subject->id,
            'level' => 'secundaria', 'difficulty_text' => 'x', 'goal' => 'prepare_exam',
            'urgency' => 'this_week', 'idempotency_key' => 'same-key',
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        StudentDiagnostic::create([
            'parent_user_id' => $parent->id, 'student_id' => $student->id, 'subject_id' => $subject->id,
            'level' => 'secundaria', 'difficulty_text' => 'y', 'goal' => 'prepare_exam',
            'urgency' => 'this_week', 'idempotency_key' => 'same-key',
        ]);
    }
}
