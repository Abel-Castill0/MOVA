<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\Lesson;
use App\Models\LessonPresenceEvent;
use App\Models\Student;
use App\Models\TeacherProfile;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Webhooks de presencia de JaaS: SOLO evidencia.
 *
 * Qué se garantiza: autenticación fail-closed, idempotencia por
 * `idempotencyKey`, atribución solo a participantes legítimos de la clase,
 * minimización de datos (sin nombre/correo/avatar) y, sobre todo, que recibir
 * eventos NO cambia estado de clase, créditos ni liquidación.
 */
class JaasPresenceWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const APP_ID = 'vpaas-magic-cookie-test';

    private const SECRET = 'wh-test-secret-long-and-random';

    private User $teacher;

    private User $parent;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        config([
            'jaas.app_id' => self::APP_ID,
            'jaas.webhooks_enabled' => true,
            'jaas.webhook_auth_token' => self::SECRET,
            // El .env/entorno real puede traer un secreto de firma (p. ej. el de staging): estos tests
            // parten de «solo token estático» y activan la firma explícitamente cuando la prueban.
            'jaas.webhook_signing_secret' => null,
        ]);

        $this->teacher = User::factory()->create();
        $this->teacher->assignRole('teacher');
        $profile = TeacherProfile::create(['user_id' => $this->teacher->id, 'is_verified' => true, 'credits_available' => 0, 'credits_reserved' => 1]);

        $this->parent = User::factory()->create();
        $this->parent->assignRole('parent');
        $student = Student::create(['parent_user_id' => $this->parent->id, 'first_name' => 'Alumno', 'last_name' => 'Prueba', 'grade_level' => 'secundaria']);

        $this->lesson = Lesson::create([
            'teacher_profile_id' => $profile->id,
            'student_id' => $student->id,
            'start_time' => now()->addHour(),
            'duration_minutes' => 60,
            'status' => 'scheduled',
            'jitsi_room' => 'mova-lesson-presence-1',
        ]);
        CreditTransaction::create([
            'teacher_profile_id' => $profile->id,
            'lesson_id' => $this->lesson->id,
            'idempotency_key' => "lesson:{$this->lesson->id}:reservation",
            'type' => 'reservation',
            'amount' => 1,
            'description' => 'Reserva por aceptación de clase',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function event(string $type = 'PARTICIPANT_JOINED', array $overrides = [], array $data = []): array
    {
        return array_replace_recursive([
            'idempotencyKey' => 'idem-'.uniqid('', true),
            'customerId' => 'cust-1',
            'eventType' => $type,
            'sessionId' => 'sess-1',
            'timestamp' => 1_790_000_000_000, // ms
            'fqn' => self::APP_ID.'/mova-lesson-presence-1',
            'appId' => self::APP_ID,
            'data' => array_merge([
                'moderator' => true,
                'name' => 'Nombre Completo Real',
                'email' => 'persona@example.test',
                'avatar' => 'https://cdn.example/avatar.png',
                'id' => (string) $this->teacher->id,
                'participantId' => 'part-1',
                'participantJid' => 'abc@xmpp',
            ], $data),
        ], $overrides);
    }

    private function send(array $payload, ?string $auth = 'Bearer '.self::SECRET)
    {
        $headers = $auth === null ? [] : ['Authorization' => $auth];

        return $this->postJson(route('webhooks.jaas.handle'), $payload, $headers);
    }

    // ── Activación y autenticación (fail closed) ─────────────────────────

    public function test_it_is_disabled_by_default(): void
    {
        config(['jaas.webhooks_enabled' => false]);

        $this->send($this->event())->assertNotFound();
        $this->assertSame(0, LessonPresenceEvent::count());
    }

    public function test_without_a_configured_secret_nothing_is_accepted_even_with_an_empty_header(): void
    {
        config(['jaas.webhook_auth_token' => null]);

        $this->send($this->event(), 'Bearer ')->assertUnauthorized();
        $this->send($this->event(), '')->assertUnauthorized();
        $this->send($this->event(), null)->assertUnauthorized();
        $this->assertSame(0, LessonPresenceEvent::count());
    }

    public function test_a_missing_or_wrong_authorization_is_rejected(): void
    {
        $this->send($this->event(), null)->assertUnauthorized();
        $this->send($this->event(), 'Bearer otro-secreto')->assertUnauthorized();
        $this->send($this->event(), self::SECRET)->assertUnauthorized(); // sin "Bearer "
        $this->assertSame(0, LessonPresenceEvent::count());
    }

    // ── Firma X-Jaas-Signature (HMAC-SHA256, https://developer.8x8.com/jaas/docs/webhooks-signatures) ──

    private const SIGNING = 'jaas-signing-secret-distinto-del-token';

    /** Cuerpo crudo firmado exactamente como lo hace JaaS: base64(HMAC-SHA256("<t>.<cuerpo>")). */
    private function sendSigned(array $payload, ?string $header = null, array $extraHeaders = [])
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $t = time();
        $header ??= 't='.$t.',v1='.base64_encode(hash_hmac('sha256', $t.'.'.$body, self::SIGNING, true));

        return $this->call('POST', route('webhooks.jaas.handle'), [], [], [], $this->transformHeadersToServerVars(array_merge([
            'CONTENT_TYPE' => 'application/json',
            'X-Jaas-Signature' => $header,
        ], $extraHeaders)), $body);
    }

    public function test_a_valid_signature_alone_is_enough_when_no_static_token_is_configured(): void
    {
        config(['jaas.webhook_auth_token' => null, 'jaas.webhook_signing_secret' => self::SIGNING]);

        $this->sendSigned($this->event())->assertOk();
        $this->assertSame(1, LessonPresenceEvent::count());
    }

    public function test_a_wrong_missing_or_stale_signature_is_rejected(): void
    {
        config(['jaas.webhook_auth_token' => null, 'jaas.webhook_signing_secret' => self::SIGNING]);
        $event = $this->event();
        $body = json_encode($event, JSON_UNESCAPED_SLASHES);
        $t = time();

        $this->sendSigned($event, 't='.$t.',v1='.base64_encode(hash_hmac('sha256', $t.'.'.$body, 'otro-secreto', true)))->assertUnauthorized();
        $this->sendSigned($event, 't='.$t)->assertUnauthorized();
        $this->sendSigned($event, 'v1=abc')->assertUnauthorized();
        $this->sendSigned($event, '')->assertUnauthorized();
        // Firma correcta pero con un timestamp de hace una hora (replay): fuera de tolerancia.
        $old = $t - 3600;
        $this->sendSigned($event, 't='.$old.',v1='.base64_encode(hash_hmac('sha256', $old.'.'.$body, self::SIGNING, true)))->assertUnauthorized();
        // Firma válida de OTRO cuerpo (cuerpo alterado en tránsito).
        $other = json_encode($this->event(), JSON_UNESCAPED_SLASHES);
        $this->sendSigned($event, 't='.$t.',v1='.base64_encode(hash_hmac('sha256', $t.'.'.$other, self::SIGNING, true)))->assertUnauthorized();
        $this->assertSame(0, LessonPresenceEvent::count());
    }

    public function test_only_the_v1_scheme_counts_and_several_v1_signatures_support_secret_rotation(): void
    {
        config(['jaas.webhook_auth_token' => null, 'jaas.webhook_signing_secret' => self::SIGNING]);
        $event = $this->event();
        $body = json_encode($event, JSON_UNESCAPED_SLASHES);
        $t = time();
        $good = base64_encode(hash_hmac('sha256', $t.'.'.$body, self::SIGNING, true));

        // Un esquema distinto de v1 con la firma correcta NO sirve (anti-degradación).
        $this->sendSigned($event, 't='.$t.',v0='.$good)->assertUnauthorized();
        // Dos v1 (secreto viejo + nuevo): basta con que una coincida; base64 con '=' se parsea bien.
        $this->sendSigned($event, 't='.$t.',v1=firma-antigua=,v1='.$good)->assertOk();
    }

    public function test_with_both_configured_both_are_required(): void
    {
        config(['jaas.webhook_signing_secret' => self::SIGNING]); // el token estático sigue configurado

        $this->sendSigned($this->event())->assertUnauthorized(); // firma OK, falta Authorization
        $this->sendSigned($this->event(), null, ['Authorization' => 'Bearer '.self::SECRET])->assertOk();
        $this->send($this->event())->assertUnauthorized(); // Authorization OK, falta firma
        $this->assertSame(1, LessonPresenceEvent::count());
    }

    public function test_a_signed_retry_with_the_same_idempotency_key_does_not_duplicate(): void
    {
        config(['jaas.webhook_auth_token' => null, 'jaas.webhook_signing_secret' => self::SIGNING]);
        $event = $this->event();

        $this->sendSigned($event)->assertOk();
        $this->sendSigned($event)->assertOk();
        $this->assertSame(1, LessonPresenceEvent::count());
    }

    public function test_the_signing_secret_and_the_static_token_are_independent_secrets(): void
    {
        // Con SOLO firma configurada, el token estático no abre la puerta (y viceversa).
        config(['jaas.webhook_auth_token' => null, 'jaas.webhook_signing_secret' => self::SIGNING]);
        $this->send($this->event(), 'Bearer '.self::SIGNING)->assertUnauthorized();

        config(['jaas.webhook_auth_token' => self::SECRET, 'jaas.webhook_signing_secret' => null]);
        $this->sendSigned($this->event())->assertUnauthorized();
        $this->assertSame(0, LessonPresenceEvent::count());
    }

    // ── Guardado correcto ────────────────────────────────────────────────

    public function test_a_joined_event_is_stored_and_attributed_to_the_teacher(): void
    {
        $this->send($this->event())->assertOk();

        $e = LessonPresenceEvent::firstOrFail();
        $this->assertSame($this->lesson->id, $e->lesson_id);
        $this->assertSame($this->teacher->id, $e->user_id);
        $this->assertSame(LessonPresenceEvent::JOINED, $e->event_type);
        $this->assertTrue($e->is_moderator);
        $this->assertNull($e->disconnect_reason);
        $this->assertSame('sess-1', $e->session_id);
        $this->assertSame(1_790_000_000, $e->occurred_at->getTimestamp()); // ms → s
    }

    public function test_a_left_event_keeps_the_disconnect_reason_and_the_parent_is_attributed(): void
    {
        $this->send($this->event('PARTICIPANT_LEFT', [], [
            'id' => (string) $this->parent->id, 'moderator' => 'false', 'disconnectReason' => 'unrecoverable_error',
        ]))->assertOk();

        $e = LessonPresenceEvent::firstOrFail();
        $this->assertSame($this->parent->id, $e->user_id);
        $this->assertSame(LessonPresenceEvent::LEFT, $e->event_type);
        $this->assertFalse($e->is_moderator);
        $this->assertSame('unrecoverable_error', $e->disconnect_reason);
    }

    public function test_second_resolution_timestamps_are_accepted_too(): void
    {
        $this->send($this->event('PARTICIPANT_JOINED', ['timestamp' => 1_790_000_000]))->assertOk();

        $this->assertSame(1_790_000_000, LessonPresenceEvent::firstOrFail()->occurred_at->getTimestamp());
    }

    // ── Idempotencia ─────────────────────────────────────────────────────

    public function test_a_retried_event_does_not_duplicate_rows(): void
    {
        $payload = $this->event();

        $this->send($payload)->assertOk();
        $this->send($payload)->assertOk();
        $this->send($payload)->assertOk();

        $this->assertSame(1, LessonPresenceEvent::count());
    }

    public function test_different_events_of_the_same_participant_are_all_kept(): void
    {
        $this->send($this->event())->assertOk();
        $this->send($this->event('PARTICIPANT_LEFT', [], ['disconnectReason' => 'left']))->assertOk();
        $this->send($this->event())->assertOk(); // reingreso

        $this->assertSame(3, LessonPresenceEvent::count());
    }

    // ── Atribución y alcance ─────────────────────────────────────────────

    public function test_an_unknown_user_id_is_stored_without_a_user_not_attributed_to_anyone(): void
    {
        $stranger = User::factory()->create();

        $this->send($this->event('PARTICIPANT_JOINED', [], ['id' => (string) $stranger->id]))->assertOk();
        $this->send($this->event('PARTICIPANT_JOINED', [], ['id' => 'abc']))->assertOk();
        $this->send($this->event('PARTICIPANT_JOINED', [], ['id' => null]))->assertOk();

        $this->assertSame(3, LessonPresenceEvent::count());
        $this->assertSame(0, LessonPresenceEvent::whereNotNull('user_id')->count());
    }

    public function test_events_for_another_tenant_or_an_unknown_room_are_acknowledged_but_ignored(): void
    {
        $this->send($this->event('PARTICIPANT_JOINED', ['fqn' => 'otro-tenant/mova-lesson-presence-1']))->assertOk();
        $this->send($this->event('PARTICIPANT_JOINED', ['fqn' => self::APP_ID.'/no-existe']))->assertOk();
        $this->send($this->event('PARTICIPANT_JOINED', ['fqn' => 'sin-barra']))->assertOk();

        $this->assertSame(0, LessonPresenceEvent::count());
    }

    public function test_event_types_mova_does_not_use_are_acknowledged_and_discarded(): void
    {
        foreach (['ROOM_CREATED', 'ROOM_DESTROYED', 'RECORDING_STARTED'] as $type) {
            $this->send($this->event($type))->assertOk();
        }

        $this->assertSame(0, LessonPresenceEvent::count());
    }

    public function test_a_malformed_body_is_a_400_and_stores_nothing(): void
    {
        $this->send(['eventType' => 'PARTICIPANT_JOINED'])->assertStatus(400);
        $this->send(array_merge($this->event(), ['timestamp' => 'ayer']))->assertStatus(400);
        $this->assertSame(0, LessonPresenceEvent::count());
    }

    // ── Privacidad (menores) ─────────────────────────────────────────────

    public function test_no_name_email_or_avatar_is_persisted(): void
    {
        $this->send($this->event())->assertOk();

        $row = json_encode(LessonPresenceEvent::firstOrFail()->getAttributes());
        foreach (['Nombre Completo Real', 'persona@example.test', 'avatar.png', 'abc@xmpp'] as $pii) {
            $this->assertStringNotContainsString($pii, $row);
        }
    }

    // ── Sin efectos de negocio ───────────────────────────────────────────

    public function test_receiving_presence_never_changes_the_lesson_credits_or_ledger(): void
    {
        $before = [
            'lesson' => $this->lesson->fresh()->only(['status', 'credits_settled_at', 'start_time']),
            'ledger' => CreditTransaction::count(),
            'credits' => TeacherProfile::first()->only(['credits_available', 'credits_reserved']),
        ];

        $this->send($this->event())->assertOk();
        $this->send($this->event('PARTICIPANT_LEFT', [], ['disconnectReason' => 'left']))->assertOk();

        $this->assertEquals($before['lesson'], $this->lesson->fresh()->only(['status', 'credits_settled_at', 'start_time']));
        $this->assertSame($before['ledger'], CreditTransaction::count());
        $this->assertEquals($before['credits'], TeacherProfile::first()->only(['credits_available', 'credits_reserved']));
    }

    // ── El JWT que emite MOVA trae el id que JaaS devolverá ──────────────

    public function test_the_issued_jwt_carries_only_the_user_id_besides_the_name_and_moderator_flag(): void
    {
        $this->lesson->update(['start_time' => now()->addMinutes(5)]);
        $privateKey = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($privateKey, $pem);
        $public = openssl_pkey_get_details($privateKey)['key'];
        config([
            'jaas.private_key' => $pem,
            'jaas.key_id' => 'vpaas-magic-cookie-test/key',
        ]);

        $token = $this->actingAs($this->teacher)->getJson(route('lessons.join', $this->lesson))
            ->assertOk()->json('jitsi_token');

        $claims = (array) JWT::decode($token, new Key($public, 'RS256'));
        $user = (array) ((array) $claims['context'])['user'];

        $this->assertSame((string) $this->teacher->id, $user['id']);
        $this->assertSame(['id', 'moderator', 'name'], collect(array_keys($user))->sort()->values()->all());
    }
}
