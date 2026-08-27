<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * F-06 — Ventana temporal de acceso a la sala de vídeo.
 *
 * Hallazgo original: la regla "disponible 15 minutos antes" vivía SOLO en
 * resources/js/utils/lessonJoin.js. El backend comprobaba autorización y
 * estado, pero no proximidad temporal, así que un GET directo a la ruta
 * devolvía un token válido días antes de la clase. Además el JWT expiraba
 * siempre a las 24h, una ventana enorme para una videollamada con un menor.
 *
 * Estos tests hacen la ventana server-authoritative y verificable.
 */
class JitsiAccessWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        config([
            'jaas.join_window_before_minutes' => 15,
            'jaas.join_grace_after_minutes'   => 120,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Fuera de la ventana ──────────────────────────────────────────────

    public function test_joining_too_early_is_refused_by_the_backend(): void
    {
        [$teacher, $lesson, $parent] = $this->lessonAt(now()->addHours(5));

        // Este es EXACTAMENTE el caso que antes devolvía 200 con un token de
        // 24h: el frontend lo ocultaba, el backend lo concedía.
        $this->actingAs($parent)->get(route('lessons.join', $lesson))->assertForbidden();
        $this->actingAs($teacher)->get(route('lessons.join', $lesson))->assertForbidden();
    }

    public function test_joining_days_before_the_class_is_refused(): void
    {
        [, $lesson, $parent] = $this->lessonAt(now()->addDays(5));

        $this->actingAs($parent)->get(route('lessons.join', $lesson))->assertForbidden();
    }

    public function test_joining_long_after_the_class_ended_is_refused(): void
    {
        // Clase de hace 6 horas: fuera de la gracia de 2h.
        [, $lesson, $parent] = $this->lessonAt(now()->subHours(6));

        $this->actingAs($parent)->get(route('lessons.join', $lesson))->assertForbidden();
    }

    // ── Dentro de la ventana ─────────────────────────────────────────────

    public function test_joining_exactly_inside_the_opening_window_is_allowed(): void
    {
        [$teacher, $lesson, $parent] = $this->lessonAt(now()->addMinutes(10));

        $this->actingAs($parent)->get(route('lessons.join', $lesson))->assertOk();
        $this->actingAs($teacher)->get(route('lessons.join', $lesson))->assertOk();
    }

    public function test_joining_while_the_class_is_running_is_allowed(): void
    {
        // Empezó hace 20 min, dura 60: en curso.
        [, $lesson, $parent] = $this->lessonAt(now()->subMinutes(20));

        $this->actingAs($parent)->get(route('lessons.join', $lesson))->assertOk();
    }

    public function test_joining_within_the_grace_period_after_the_class_is_allowed(): void
    {
        // Terminó hace 30 min; la gracia es de 120 min. Una clase que se alarga
        // o un cierre de temas no debe quedarse fuera.
        [, $lesson, $parent] = $this->lessonAt(now()->subMinutes(90));

        $this->actingAs($parent)->get(route('lessons.join', $lesson))->assertOk();
    }

    public function test_the_window_is_configurable(): void
    {
        config(['jaas.join_window_before_minutes' => 60]);
        [, $lesson, $parent] = $this->lessonAt(now()->addMinutes(45));

        // Con la ventana por defecto (15 min) esto sería 403; con 60, entra.
        $this->actingAs($parent)->get(route('lessons.join', $lesson))->assertOk();
    }

    // ── Expiración del JWT acotada a la ventana ──────────────────────────

    public function test_the_jwt_no_longer_lives_for_24_hours(): void
    {
        [, $lesson, $parent] = $this->lessonAt(now()->addMinutes(10));

        $token = $this->actingAs($parent)->get(route('lessons.join', $lesson))->json('jitsi_token');
        $payload = $this->decode($token);

        // Antes: exp = now + 86400 SIEMPRE. Ahora queda acotado por el fin de
        // la clase + la gracia, muy por debajo de 24h.
        $this->assertLessThan(
            now()->addHours(24)->timestamp,
            $payload['exp'],
            'El token no debe seguir viviendo 24h.'
        );
        $this->assertGreaterThan(now()->timestamp, $payload['exp'], 'El token no debe emitirse ya vencido.');
    }

    public function test_the_jwt_expires_around_the_end_of_the_access_window(): void
    {
        $start = now()->addMinutes(10);
        [, $lesson, $parent] = $this->lessonAt($start);

        $payload = $this->decode(
            $this->actingAs($parent)->get(route('lessons.join', $lesson))->json('jitsi_token')
        );

        // Fin de clase (start + 60 min) + gracia (120 min).
        $expected = $start->copy()->addMinutes(60 + 120)->timestamp;

        $this->assertEqualsWithDelta($expected, $payload['exp'], 60);
    }

    public function test_the_jwt_is_still_scoped_to_the_specific_room(): void
    {
        [, $lesson, $parent] = $this->lessonAt(now()->addMinutes(10));

        $payload = $this->decode(
            $this->actingAs($parent)->get(route('lessons.join', $lesson))->json('jitsi_token')
        );

        // El endurecimiento temporal no debe haber debilitado el scope: el
        // token sigue sirviendo para UNA sala, nunca con comodín.
        $this->assertSame($lesson->fresh()->makeVisible('jitsi_room')->jitsi_room, $payload['room']);
        $this->assertNotSame('*', $payload['room']);
        $this->assertSame('jitsi', $payload['aud']);
        $this->assertSame('chat', $payload['iss']);
    }

    public function test_the_jwt_still_disables_recording_and_streaming(): void
    {
        [, $lesson, $parent] = $this->lessonAt(now()->addMinutes(10));

        $payload = $this->decode(
            $this->actingAs($parent)->get(route('lessons.join', $lesson))->json('jitsi_token')
        );

        $features = $payload['context']->features;
        $this->assertFalse($features->recording);
        $this->assertFalse($features->livestreaming);
        $this->assertFalse($features->transcription);
    }

    // ── La autorización sigue mandando por encima de la ventana ──────────

    public function test_an_unrelated_user_is_still_refused_even_inside_the_window(): void
    {
        [, $lesson] = $this->lessonAt(now()->addMinutes(10));
        $stranger = $this->userWithRole('parent');

        $this->actingAs($stranger)->get(route('lessons.join', $lesson))->assertForbidden();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function decode(string $token): array
    {
        $publicKey = openssl_pkey_get_details(openssl_pkey_get_private(config('jaas.private_key')))['key'];

        return (array) JWT::decode($token, new Key($publicKey, 'RS256'));
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['password' => 'password']);
        $user->assignRole($role);

        return $user;
    }

    /** @return array{0: User, 1: Lesson, 2: User} */
    private function lessonAt(Carbon $startTime): array
    {
        $teacher = $this->userWithRole('teacher');
        $profile = TeacherProfile::create([
            'user_id'           => $teacher->id,
            'is_verified'       => true,
            'credits_available' => 0,
            'credits_reserved'  => 1,
        ]);
        $subject = Subject::create([
            'name'  => 'Materia '.fake()->unique()->numerify('####'),
            'level' => 'secundaria',
        ]);
        $profile->subjects()->attach($subject->id);

        $parent  = $this->userWithRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name'     => 'Alumno',
            'last_name'      => 'Prueba',
            'grade_level'    => 'secundaria',
        ]);

        $lesson = Lesson::create([
            'teacher_profile_id' => $profile->id,
            'student_id'         => $student->id,
            'start_time'         => $startTime,
            'duration_minutes'   => 60,
            'status'             => 'scheduled',
            'jitsi_room'         => 'mova-lesson-window-'.fake()->unique()->numerify('######'),
        ]);

        CreditTransaction::create([
            'teacher_profile_id' => $profile->id,
            'lesson_id'          => $lesson->id,
            'idempotency_key'    => "lesson:{$lesson->id}:reservation",
            'type'               => 'reservation',
            'amount'             => 1,
            'description'        => 'Reserva por aceptación de clase',
        ]);

        return [$teacher, $lesson, $parent];
    }
}
