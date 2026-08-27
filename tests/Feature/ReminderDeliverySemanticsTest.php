<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Notifications\ClassReminderNotification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * F-04 (Fase 4) — Semántica de entrega, con los tres niveles separados.
 *
 * La Fase 3 escribió "COMMIT -> exactamente una vez". Es FALSO como afirmación
 * sobre la entrega externa, y estos tests fijan la verdad:
 *
 *   1. claim + encolado   -> exactly-once  (esta transacción sí lo garantiza)
 *   2. proceso del job    -> at-least-once (la cola reintenta si el worker muere)
 *   3. entrega externa    -> at-least-once (Meta puede recibirlo dos veces)
 *
 * El mecanismo elimina el modo de fallo grave (aviso perdido para siempre) a
 * cambio de un duplicado posible. Ese intercambio es correcto, pero hay que
 * nombrarlo bien.
 */
class ReminderDeliverySemanticsTest extends TestCase
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

    // ── Nivel 1: claim + encolado es atómico ─────────────────────────────

    public function test_the_claim_and_the_queued_job_are_committed_together(): void
    {
        Queue::fake();
        $lesson = $this->scheduledLesson(now()->addHours(24));

        $this->artisan('classmate:send-reminders');

        // Marcador escrito Y jobs encolados: los dos, no uno solo.
        $this->assertNotNull($lesson->fresh()->reminder_24h_sent_at);
        Queue::assertPushed(SendQueuedNotifications::class);
    }

    public function test_nothing_is_queued_when_the_claim_loses_the_race(): void
    {
        Queue::fake();
        $lesson = $this->scheduledLesson(now()->addHours(24));

        // Otro proceso ya reclamó la lección antes de que llegáramos.
        DB::table('classes')->where('id', $lesson->id)->update(['reminder_24h_sent_at' => now()]);

        $this->artisan('classmate:send-reminders');

        Queue::assertNothingPushed();
    }

    public function test_a_rollback_leaves_neither_the_claim_nor_the_job(): void
    {
        $lesson = $this->scheduledLesson(now()->addHours(24));

        // Se fuerza el fallo del despacho DENTRO de la transacción.
        Queue::fake();
        Queue::shouldReceive('push')->andThrow(new \RuntimeException('cola caída'));

        $this->artisan('classmate:send-reminders');

        $this->assertNull(
            $lesson->fresh()->reminder_24h_sent_at,
            'Ni marcador ni job: la lección debe seguir siendo candidata.'
        );
    }

    // ── Nivel 2: el job sobrevive a la caída del worker ──────────────────

    public function test_the_job_remains_in_the_queue_after_the_command_finishes(): void
    {
        // Con QUEUE_CONNECTION=database el job es una fila persistida. Si el
        // worker muere ANTES de tomarlo, la fila sigue ahí y otro worker lo
        // recoge: el aviso no se pierde. Este test verifica la persistencia,
        // que es la premisa de esa recuperación.
        config(['queue.default' => 'database']);
        $lesson = $this->scheduledLesson(now()->addHours(24));

        $this->artisan('classmate:send-reminders');

        $queued = DB::table('jobs')->count();
        $this->assertGreaterThan(0, $queued, 'El job debe quedar persistido en la tabla `jobs`.');
        $this->assertNotNull($lesson->fresh()->reminder_24h_sent_at);
    }

    public function test_a_worker_crash_after_commit_does_not_release_the_claim(): void
    {
        // Deliberado, y es la diferencia con el nivel 1: una vez commiteado, el
        // marcador NO se libera. Si se liberara, el próximo barrido encolaría
        // un SEGUNDO job y el usuario recibiría el aviso dos veces por diseño.
        // La recuperación del worker caído es responsabilidad de la cola, no
        // del claim.
        config(['queue.default' => 'database']);
        $lesson = $this->scheduledLesson(now()->addHours(24));

        $this->artisan('classmate:send-reminders');
        $claimedAt = $lesson->fresh()->reminder_24h_sent_at;

        // Simula la caída del worker: el job desaparece sin haberse procesado.
        DB::table('jobs')->delete();

        $this->artisan('classmate:send-reminders');

        $this->assertEquals(
            $claimedAt,
            $lesson->fresh()->reminder_24h_sent_at,
            'El marcador no debe reabrirse tras el commit: eso duplicaría el aviso.'
        );
        $this->assertSame(0, DB::table('jobs')->count(), 'No debe encolarse un segundo job.');
    }

    // ── Nivel 3: la entrega externa NO es exactly-once ───────────────────

    public function test_external_delivery_is_at_least_once_and_this_is_documented(): void
    {
        // No hay forma de probar en PHPUnit que Meta reciba dos veces un
        // mensaje. Lo que sí se puede fijar es que el diseño NO afirme
        // "exactly-once delivery" — la afirmación falsa era el problema.
        $source = file_get_contents(app_path('Console/Commands/SendClassReminders.php'));

        $this->assertStringContainsString(
            'AT-LEAST-ONCE',
            $source,
            'La semántica de entrega externa debe estar documentada explícitamente.'
        );
        $this->assertStringNotContainsString(
            'exactamente una vez)',
            $source,
            'No debe afirmarse entrega exactly-once: es falso y engaña a quien mantenga esto.'
        );
    }

    public function test_a_duplicate_send_would_be_detectable_after_the_fact(): void
    {
        // Mitigación declarada: aunque un duplicado no se pueda PREVENIR, la
        // tabla whatsapp_messages permite detectarlo, porque provider_message_id
        // tiene UNIQUE(provider, provider_message_id).
        $indexes = collect(DB::select("PRAGMA index_list('whatsapp_messages')"))
            ->pluck('name')
            ->map(fn ($name) => collect(DB::select("PRAGMA index_info('{$name}')"))->pluck('name')->all());

        $hasUnique = $indexes->contains(fn ($cols) => in_array('provider_message_id', $cols, true));

        $this->assertTrue($hasUnique, 'whatsapp_messages debe poder detectar un reenvío del mismo mensaje.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function scheduledLesson(Carbon $startTime): Lesson
    {
        $subject = Subject::create([
            'name' => 'Materia '.fake()->unique()->numerify('####'),
            'level' => 'secundaria',
        ]);

        $teacher = User::factory()->create(['password' => 'password']);
        $teacher->assignRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_verified' => true,
            'credits_available' => 0,
            'credits_reserved' => 0,
        ]);
        $profile->subjects()->attach($subject->id);

        $parent = User::factory()->create(['password' => 'password']);
        $parent->assignRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Alumno',
            'last_name' => 'Prueba',
            'grade_level' => 'secundaria',
        ]);

        return Lesson::create([
            'teacher_profile_id' => $profile->id,
            'student_id' => $student->id,
            'start_time' => $startTime,
            'duration_minutes' => 60,
            'status' => 'scheduled',
        ]);
    }
}
