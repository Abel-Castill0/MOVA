<?php

namespace Tests\Feature;

use App\Models\ClassRequest;
use App\Models\CreditTransaction;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C-2 v1 — reschedule() podía cambiar duration_minutes de una clase ya
 * reservada sin recalcular price_frozen_pen ni los créditos: el profesor
 * podía terminar dictando 4h cobrando/reservando lo de 30min. La v1 cierra
 * el exploit bloqueando por completo el cambio de duración; permitir
 * cambiarla de forma segura (recalculando costo, créditos y ledger) queda
 * para una v2 fuera de este alcance.
 */
class RescheduleTest extends TestCase
{
    use RefreshDatabase;

    // ── 1-2. Duración prohibida ──────────────────────────────────────────────

    /**
     * assertStatus(422) era la aserción original en ambas pruebas de esta
     * sección — un abort_if() crudo también produce 422, pero como
     * HttpException plano, no como ValidationException real: Inertia nunca
     * lo traducía a form.errors, así que ninguna de las dos demostró jamás
     * que el mensaje llegara al usuario (mismo punto ciego ya encontrado 3
     * veces antes esta sesión). Corregidas a assertSessionHasErrors() con
     * el texto exacto tras convertir ese abort_if() a un ValidationException
     * real — verificado con revert-confirm-restore.
     */
    public function test_reschedule_rejects_a_different_duration(): void
    {
        [$teacher, $profile, $lesson, $parent] = $this->scenario();

        $response = $this->actingAs($parent)->post(route('lessons.reschedule', $lesson), [
            'start_time' => now()->addDays(2)->toIso8601String(),
            'duration_minutes' => 120, // original es 60
        ]);

        $response->assertRedirect()->assertSessionHasErrors([
            'duration_minutes' => 'No puedes cambiar la duración de una clase agendada. Contacta al profesor.',
        ]);
        $this->assertUnchanged($lesson, $profile, 60);
    }

    public function test_reschedule_rejects_duration_even_when_it_equals_the_original(): void
    {
        [$teacher, $profile, $lesson, $parent] = $this->scenario();

        // No basta con que Laravel ignore el campo cuando "coincide por
        // casualidad": la API debe rechazar la sola presencia del campo,
        // para que un cliente viejo o una petición manipulada no crea que
        // logró tocar la duración.
        $response = $this->actingAs($parent)->post(route('lessons.reschedule', $lesson), [
            'start_time' => now()->addDays(2)->toIso8601String(),
            'duration_minutes' => 60, // igual al original
        ]);

        $response->assertRedirect()->assertSessionHasErrors([
            'duration_minutes' => 'No puedes cambiar la duración de una clase agendada. Contacta al profesor.',
        ]);
        $this->assertUnchanged($lesson, $profile, 60);
    }

    // ── 3. Reschedule normal ─────────────────────────────────────────────────

    public function test_reschedule_with_only_start_time_succeeds_and_keeps_everything_else_intact(): void
    {
        [$teacher, $profile, $lesson, $parent] = $this->scenario();
        $newStart = now()->addDays(3)->setTime(15, 0);

        $response = $this->actingAs($parent)->post(route('lessons.reschedule', $lesson), [
            'start_time' => $newStart->toIso8601String(),
        ]);

        $response->assertSessionHasNoErrors();
        $lesson->refresh();

        $this->assertTrue($lesson->start_time->equalTo($newStart));
        $this->assertNotNull($lesson->original_start_time);
        $this->assertUnchanged($lesson, $profile, 60);
    }

    // ── 4. Estados no reprogramables ─────────────────────────────────────────

    /**
     * assertStatus(422) era la aserción original — corregida al mismo
     * ValidationException real que reemplazó el abort_unless() crudo
     * (ver comentario de la sección de duración arriba para el porqué).
     *
     * @dataProvider nonScheduledStatusProvider
     */
    public function test_reschedule_is_rejected_for_every_non_scheduled_status(string $status): void
    {
        [$teacher, $profile, $lesson, $parent] = $this->scenario($status);

        $response = $this->actingAs($parent)->post(route('lessons.reschedule', $lesson), [
            'start_time' => now()->addDays(2)->toIso8601String(),
        ]);

        $response->assertRedirect()->assertSessionHasErrors([
            'reschedule' => 'Solo se pueden reprogramar clases programadas.',
        ]);
        $lesson->refresh();
        $this->assertSame($status, $lesson->status);
    }

    /**
     * Todos los estados reales del enum además de 'scheduled' — ninguno
     * inventado. F-09: se retiró 'in_progress' (valor muerto eliminado del
     * enum) y se añadió 'needs_admin_review', que sí es alcanzable
     * (SettleLessons::escalateUnconfirmed) y tampoco debe permitir reprogramar.
     */
    public static function nonScheduledStatusProvider(): array
    {
        return [
            ['paid'],
            ['pending_parent_confirmation'],
            ['completed'],
            ['cancelled'],
            ['needs_admin_review'],
        ];
    }

    // ── 5. Autorización ──────────────────────────────────────────────────────

    public function test_owner_parent_can_reschedule(): void
    {
        [, , $lesson, $parent] = $this->scenario();

        $this->actingAs($parent)
            ->post(route('lessons.reschedule', $lesson), ['start_time' => now()->addDays(2)->toIso8601String()])
            ->assertSessionHasNoErrors();
    }

    public function test_assigned_teacher_can_reschedule(): void
    {
        [$teacher, , $lesson] = $this->scenario();

        $this->actingAs($teacher)
            ->post(route('lessons.reschedule', $lesson), ['start_time' => now()->addDays(2)->toIso8601String()])
            ->assertSessionHasNoErrors();
    }

    public function test_unrelated_parent_cannot_reschedule(): void
    {
        [, , $lesson] = $this->scenario();
        $stranger = $this->userWithRole('parent');

        $this->actingAs($stranger)
            ->post(route('lessons.reschedule', $lesson), ['start_time' => now()->addDays(2)->toIso8601String()])
            ->assertForbidden();
    }

    public function test_unrelated_teacher_cannot_reschedule(): void
    {
        [, , $lesson] = $this->scenario();
        $stranger = $this->userWithRole('teacher');
        TeacherProfile::create(['user_id' => $stranger->id, 'is_verified' => true]);

        $this->actingAs($stranger)
            ->post(route('lessons.reschedule', $lesson), ['start_time' => now()->addDays(2)->toIso8601String()])
            ->assertForbidden();
    }

    // ── 6. Inmutabilidad financiera ──────────────────────────────────────────

    public function test_reschedule_never_touches_credits_or_the_ledger(): void
    {
        [$teacher, $profile, $lesson, $parent] = $this->scenario();
        $profile->update(['credits_available' => 3, 'credits_reserved' => 1]);
        $ledgerCountBefore = CreditTransaction::count();

        $this->actingAs($parent)->post(route('lessons.reschedule', $lesson), [
            'start_time' => now()->addDays(2)->toIso8601String(),
        ]);

        $profile->refresh();
        $this->assertSame(3, $profile->credits_available);
        $this->assertSame(1, $profile->credits_reserved);
        $this->assertSame($ledgerCountBefore, CreditTransaction::count());
        $this->assertSame(1, $lesson->reservedCreditAmount());
        $this->assertFalse(
            CreditTransaction::where('lesson_id', $lesson->id)->whereIn('type', ['consumption', 'refund'])->exists()
        );
    }

    // ── 7. cancel() vs reschedule() ──────────────────────────────────────────

    public function test_a_cancelled_lesson_cannot_be_rescheduled(): void
    {
        [$teacher, $profile, $lesson, $parent] = $this->scenario();

        $this->actingAs($parent)->post(route('lessons.cancel', $lesson))->assertSessionHasNoErrors();
        $lesson->refresh();
        $this->assertSame('cancelled', $lesson->status);

        $response = $this->actingAs($parent)->post(route('lessons.reschedule', $lesson), [
            'start_time' => now()->addDays(2)->toIso8601String(),
        ]);

        $response->assertRedirect()->assertSessionHasErrors([
            'reschedule' => 'Solo se pueden reprogramar clases programadas.',
        ]);
        $lesson->refresh();
        $this->assertSame('cancelled', $lesson->status);
    }

    // ── 8. Solapamiento ───────────────────────────────────────────────────────

    public function test_reschedule_into_an_overlapping_slot_is_rejected(): void
    {
        [$teacher, $profile, $lessonA, $parent] = $this->scenario();
        // Segunda clase del mismo profesor, ya ocupando el horario destino.
        [, , $lessonB] = $this->scenario('scheduled', $teacher, $profile);
        $lessonB->update(['start_time' => now()->addDays(5)->setTime(10, 0)]);

        $response = $this->actingAs($parent)->post(route('lessons.reschedule', $lessonA), [
            // Se solapa con lessonB (10:00-11:00): entra a las 10:30.
            'start_time' => now()->addDays(5)->setTime(10, 30)->toIso8601String(),
        ]);

        // El solapamiento se rechaza vía ValidationException (mismo mecanismo
        // que store()), que Laravel resuelve como redirect con errores en
        // sesión para peticiones sin cabecera X-Inertia — no como 422 crudo,
        // que sí aplica a los abort_if/abort_unless de este mismo método.
        $response->assertSessionHasErrors('start_time');
        $lessonA->refresh();
        $this->assertFalse($lessonA->start_time->equalTo(now()->addDays(5)->setTime(10, 30)));
    }

    /**
     * Reprogramar una clase a un horario que se solapa CONSIGO MISMA (su
     * propio start_time actual) no debe fallar por falso solapamiento: el
     * chequeo reutilizado debe excluir la propia lesson, igual que ya lo
     * hacía la query inline que reemplazamos.
     */
    public function test_rescheduling_to_a_time_that_overlaps_only_with_itself_is_allowed(): void
    {
        [$teacher, $profile, $lesson, $parent] = $this->scenario();
        $sameSlotButLater = $lesson->start_time->copy()->addMinutes(15);

        $this->actingAs($parent)
            ->post(route('lessons.reschedule', $lesson), ['start_time' => $sameSlotButLater->toIso8601String()])
            ->assertSessionHasNoErrors();
    }

    // ── 9. Concurrencia ───────────────────────────────────────────────────────

    /**
     * LIMITACIÓN DOCUMENTADA: los tests corren sobre SQLite en memoria con
     * una sola conexión (ver phpunit.xml, DB_CONNECTION=sqlite,
     * DB_DATABASE=:memory:). PHPUnit ejecuta este test en un único proceso
     * y una única conexión, así que NO es posible reproducir dos
     * transacciones concurrentes reales aquí — dos "llamadas simultáneas"
     * son, en la práctica, dos llamadas secuenciales en la misma conexión.
     *
     * Lo que este test SÍ prueba, de forma determinista: que reschedule()
     * relee el estado de la Lesson bajo lockForUpdate() dentro de la
     * transacción en vez de confiar en la instancia cargada antes de
     * empezar — que es precisamente la condición necesaria (aunque no
     * suficiente por sí sola) para que el lock real de MySQL en producción
     * sea efectivo. No demuestra ausencia de race condition en producción;
     * solo que el código no tiene el bug obvio de "leer antes del lock y
     * confiar en ese valor después".
     */
    public function test_reschedule_reevaluates_status_under_lock_not_from_a_stale_read(): void
    {
        [$teacher, $profile, $lesson, $parent] = $this->scenario();

        // Instancia "vieja" cargada antes de que la clase sea cancelada por
        // otra parte — simula que el request ya tenía el modelo en memoria
        // cuando la cancelación ocurrió en otra petición.
        $staleLesson = Lesson::find($lesson->id);

        $lesson->update(['status' => 'cancelled']);

        $response = $this->actingAs($parent)->post(route('lessons.reschedule', $staleLesson), [
            'start_time' => now()->addDays(2)->toIso8601String(),
        ]);

        $response->assertRedirect()->assertSessionHasErrors([
            'reschedule' => 'Solo se pueden reprogramar clases programadas.',
        ]);
        $this->assertSame('cancelled', $lesson->fresh()->status);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{0: User, 1: TeacherProfile, 2: Lesson, 3: User} [$teacher, $profile, $lesson, $parent]
     */
    private function scenario(string $status = 'scheduled', ?User $teacher = null, ?TeacherProfile $profile = null): array
    {
        if (!$teacher || !$profile) {
            $teacher = $this->userWithRole('teacher');
            $profile = TeacherProfile::create([
                'user_id' => $teacher->id,
                'is_verified' => true,
                'credits_available' => 0,
                'credits_reserved' => 1,
            ]);
        }

        $subject = Subject::create(['name' => 'Materia '.fake()->unique()->numerify('####'), 'level' => 'secundaria']);
        $profile->subjects()->attach($subject->id);

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
            'start_time' => now()->addDay()->setTime(9, 0),
            'duration_minutes' => 60,
            'price_frozen_pen' => 20.00,
            'status' => $status,
        ]);

        CreditTransaction::create([
            'teacher_profile_id' => $profile->id,
            'lesson_id' => $lesson->id,
            'idempotency_key' => "lesson:{$lesson->id}:reservation",
            'type' => 'reservation',
            'amount' => 1,
            'description' => 'Reserva por aceptación de clase',
        ]);

        return [$teacher, $profile, $lesson, $parent];
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['password' => 'password']);
        $user->assignRole($role);

        return $user;
    }

    private function assertUnchanged(Lesson $lesson, TeacherProfile $profile, int $expectedDuration): void
    {
        $lesson->refresh();
        $profile->refresh();

        $this->assertSame($expectedDuration, $lesson->duration_minutes);
        // Valor numérico, no formato de string: SQLite (tests) no aplica el
        // mismo padding decimal que MySQL (producción) para una columna sin
        // cast explícito en el modelo — lo que importa aquí es que el precio
        // congelado en la creación (20.00) siga siendo el mismo valor.
        $this->assertEquals(20.00, (float) $lesson->price_frozen_pen);
        $this->assertSame(0, $profile->credits_available);
        $this->assertSame(1, $profile->credits_reserved);
        $this->assertSame(1, $lesson->reservedCreditAmount());
    }
}
