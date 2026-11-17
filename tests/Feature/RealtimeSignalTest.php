<?php

namespace Tests\Feature;

use App\Models\ClassRequest;
use App\Models\CreditTransaction;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tiempo real con Azure SignalR (modo Serverless): la señal solo dice QUÉ cambió y va únicamente a quien corresponde; el
 * navegador pide los datos por HTTP. Aquí no se llama al servicio real: se fija el contrato (a quién, qué lleva, cuándo).
 */
class RealtimeSignalTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'clave-de-prueba-signalr-0123456789abcdef';
    private const ENDPOINT = 'https://mova-test.service.signalr.net';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['parent', 'teacher'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Notification::fake();
    }

    private function enable(): void
    {
        config(['realtime.driver' => 'signalr', 'realtime.signalr.endpoint' => self::ENDPOINT, 'realtime.signalr.access_key' => self::KEY, 'realtime.signalr.hub' => 'mova']);
        Http::fake([self::ENDPOINT.'/*' => Http::response('', 202)]);
    }

    /** @return list<array{user: int, body: array, jwt: string}> */
    private function sent(): array
    {
        return Http::recorded()->map(function (array $pair) {
            /** @var Request $request */
            [$request] = $pair;
            preg_match('#/users/(\d+)$#', $request->url(), $m);

            return ['user' => (int) ($m[1] ?? 0), 'body' => $request->data(), 'jwt' => trim(str_replace('Bearer', '', $request->header('Authorization')[0] ?? '')), 'url' => $request->url()];
        })->all();
    }

    private function parentWithStudent(): array
    {
        $parent = User::factory()->create(['parental_control' => false]);
        $parent->assignRole('parent');
        $student = Student::create(['parent_user_id' => $parent->id, 'first_name' => 'Alumno', 'last_name' => 'Sintético', 'grade_level' => 'secundaria']);

        return [$parent, $student];
    }

    private function teacher(Subject $subject, bool $verified = true): array
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');
        $profile = TeacherProfile::create(['user_id' => $teacher->id, 'is_verified' => $verified, 'hourly_rate' => 20]);
        $profile->subjects()->attach($subject->id);

        return [$teacher, $profile];
    }

    public function test_nothing_is_sent_while_the_driver_is_off(): void
    {
        Http::fake();
        $subject = Subject::create(['name' => 'Álgebra', 'level' => 'todos']);
        [, $student] = $this->parentWithStudent();
        $this->teacher($subject);

        ClassRequest::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'help_needed' => 'x', 'status' => 'open']);

        Http::assertNothingSent();
    }

    public function test_a_new_open_request_signals_only_the_parent_and_the_eligible_teacher_with_no_private_data(): void
    {
        $this->enable();
        $algebra = Subject::create(['name' => 'Álgebra', 'level' => 'todos']);
        $physics = Subject::create(['name' => 'Física', 'level' => 'todos']);
        [$parent, $student] = $this->parentWithStudent();
        [$teacher] = $this->teacher($algebra);
        [$otherSubject] = $this->teacher($physics);
        [$unverified] = $this->teacher($algebra, verified: false);

        $request = ClassRequest::create(['student_id' => $student->id, 'subject_id' => $algebra->id, 'help_needed' => 'Dato privado del menor', 'status' => 'open']);

        $sent = collect($this->sent());
        $this->assertEqualsCanonicalizing([$parent->id, $teacher->id], $sent->pluck('user')->all());
        $this->assertNotContains($otherSubject->id, $sent->pluck('user')->all());
        $this->assertNotContains($unverified->id, $sent->pluck('user')->all());

        foreach ($sent as $message) {
            $this->assertSame('signal', $message['body']['target']);
            $signal = $message['body']['arguments'][0];
            $this->assertEqualsCanonicalizing(['id', 'topics', 'at'], array_keys($signal), 'La señal solo lleva id, temas y hora.');
            $this->assertContains('requests', $signal['topics']);
            $json = json_encode($message['body']);
            foreach (['Dato privado', 'Alumno', (string) $student->first_name, 'help_needed', 'student'] as $secret) {
                $this->assertStringNotContainsString($secret, $json);
            }
            $this->assertStringNotContainsString('"'.$request->id.'"', $json);
            // Cada llamada REST va firmada para ESA url con la clave del servicio.
            $claims = JWT::decode($message['jwt'], new Key(self::KEY, 'HS256'));
            $this->assertSame($message['url'], $claims->aud);
        }
    }

    public function test_a_request_waiting_for_parent_approval_is_not_signalled_to_any_teacher(): void
    {
        $this->enable();
        $subject = Subject::create(['name' => 'Álgebra', 'level' => 'todos']);
        [$parent, $student] = $this->parentWithStudent();
        [$teacher] = $this->teacher($subject);

        $request = ClassRequest::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'help_needed' => 'x', 'status' => 'pending_parent_approval']);
        $this->assertSame([$parent->id], array_column($this->sent(), 'user'));

        // Al aprobar, sí: el profesor se entera de que hay algo nuevo.
        Http::fake([self::ENDPOINT.'/*' => Http::response('', 202)]);
        $request->update(['status' => 'open']);
        $this->assertEqualsCanonicalizing([$parent->id, $teacher->id], array_column($this->sent(), 'user'));
    }

    public function test_unchanged_saves_do_not_signal_and_a_rolled_back_transaction_never_signals(): void
    {
        $this->enable();
        $subject = Subject::create(['name' => 'Álgebra', 'level' => 'todos']);
        [, $student] = $this->parentWithStudent();
        $this->teacher($subject);
        $request = ClassRequest::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'help_needed' => 'x', 'status' => 'open']);

        Http::fake([self::ENDPOINT.'/*' => Http::response('', 202)]);
        $request->update(['help_needed' => 'otro texto']); // no cambia nada que interese a la bandeja
        Http::assertNothingSent();

        try {
            DB::transaction(function () use ($request) {
                $request->update(['status' => 'expired']);
                throw new \RuntimeException('revertir');
            });
        } catch (\RuntimeException) {
        }
        Http::assertNothingSent();
        $this->assertSame('open', $request->fresh()->status);
    }

    public function test_a_credit_ledger_entry_signals_only_the_owner_teacher_with_credit_topics(): void
    {
        $this->enable();
        $subject = Subject::create(['name' => 'Álgebra', 'level' => 'todos']);
        [$teacher, $profile] = $this->teacher($subject);
        [$other] = $this->teacher($subject);

        CreditTransaction::create(['teacher_profile_id' => $profile->id, 'idempotency_key' => 'test-'.uniqid(), 'type' => 'deposit', 'amount' => 5, 'description' => 'Prueba']);

        $sent = $this->sent();
        $this->assertSame([$teacher->id], array_column($sent, 'user'));
        $this->assertContains('credits', $sent[0]['body']['arguments'][0]['topics']);
        $this->assertNotContains($other->id, array_column($sent, 'user'));
        $this->assertStringNotContainsString('"5"', json_encode($sent[0]['body']));
    }

    public function test_a_failing_realtime_service_never_breaks_the_business_operation(): void
    {
        config(['realtime.driver' => 'signalr', 'realtime.signalr.endpoint' => self::ENDPOINT, 'realtime.signalr.access_key' => self::KEY]);
        Http::fake([self::ENDPOINT.'/*' => Http::response('boom', 500)]);
        $subject = Subject::create(['name' => 'Álgebra', 'level' => 'todos']);
        [, $student] = $this->parentWithStudent();
        $this->teacher($subject);

        $request = ClassRequest::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'help_needed' => 'x', 'status' => 'open']);
        $this->assertDatabaseHas('class_requests', ['id' => $request->id, 'status' => 'open']);

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('sin red'));
        $request->update(['status' => 'expired']);
        $this->assertSame('expired', $request->fresh()->status);
    }

    public function test_many_recipients_are_signalled_in_one_parallel_round_and_a_failure_pauses_the_service_for_a_minute(): void
    {
        $this->enable();
        $subject = Subject::create(['name' => 'Álgebra', 'level' => 'todos']);
        [, $student] = $this->parentWithStudent();
        foreach (range(1, 6) as $_) {
            $this->teacher($subject);
        }

        ClassRequest::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'help_needed' => 'x', 'status' => 'open']);
        $this->assertCount(7, $this->sent(), 'Un envío por destinatario (padre + 6 profesores), todos en la misma ronda.');

        // El servicio cae: se pausa y las operaciones siguientes no pagan ninguna espera ni intentan nada.
        \Illuminate\Support\Facades\Cache::flush();
        Http::swap(new \Illuminate\Http\Client\Factory()); // empieza de cero: el primer simulacro seguiría respondiendo 202
        Http::fake([self::ENDPOINT.'/*' => Http::response('boom', 503)]);
        ClassRequest::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'help_needed' => 'y', 'status' => 'open']);
        $callsAfterFailure = count($this->sent());
        $this->assertGreaterThan(0, $callsAfterFailure);
        $this->assertTrue(\Illuminate\Support\Facades\Cache::has('realtime:signalr:down'));

        ClassRequest::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'help_needed' => 'z', 'status' => 'open']);
        $this->assertCount($callsAfterFailure, $this->sent(), 'Con el servicio pausado no se hace ninguna llamada nueva.');
    }

    public function test_a_suspended_user_cannot_negotiate_a_connection(): void
    {
        $this->enable();
        [$parent] = $this->parentWithStudent();
        $parent->update(['suspended_at' => now()]);

        $this->actingAs($parent->fresh())->postJson(route('realtime.negotiate'))->assertRedirect(route('suspended'));
    }

    public function test_negotiate_requires_login_and_binds_the_token_to_the_session_user_only(): void
    {
        $this->enable();
        [$parent] = $this->parentWithStudent();
        $subject = Subject::create(['name' => 'Álgebra', 'level' => 'todos']);
        [$teacher] = $this->teacher($subject);

        $this->postJson(route('realtime.negotiate'))->assertStatus(401);

        // Un cuerpo malicioso no cambia a quién pertenece el token.
        $response = $this->actingAs($parent)->postJson(route('realtime.negotiate'), ['user_id' => $teacher->id, 'nameid' => $teacher->id])->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $data = $response->json();
        $this->assertSame(self::ENDPOINT.'/client/?hub=mova', $data['url']);
        $claims = JWT::decode($data['accessToken'], new Key(self::KEY, 'HS256'));
        $this->assertSame((string) $parent->id, $claims->nameid);
        $this->assertSame($data['url'], $claims->aud);
        $this->assertGreaterThan(time(), $claims->exp);
        $this->assertStringNotContainsString(self::KEY, $response->getContent());
    }

    public function test_negotiate_answers_204_and_the_shared_prop_says_disabled_when_the_driver_is_off(): void
    {
        [$parent] = $this->parentWithStudent();

        $this->actingAs($parent)->postJson(route('realtime.negotiate'))->assertNoContent();

        $props = $this->actingAs($parent)->get(route('class-requests.index'))->assertOk()->inertiaPage()['props'];
        $this->assertSame(['enabled' => false, 'driver' => null, 'negotiateUrl' => null], $props['realtime']);

        $this->enable();
        $props = $this->actingAs($parent)->get(route('class-requests.index'))->assertOk()->inertiaPage()['props'];
        $this->assertSame(['enabled' => true, 'driver' => 'signalr', 'negotiateUrl' => '/realtime/negotiate'], $props['realtime']);
        $this->assertStringNotContainsString(self::KEY, json_encode($props));
        $this->assertStringNotContainsString('mova-test.service', json_encode($props));
    }
}
