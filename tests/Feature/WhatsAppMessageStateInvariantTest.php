<?php

namespace Tests\Feature;

use App\Models\WhatsAppMessage;
use App\WhatsApp\MetaCloudApiProvider;
use App\WhatsApp\WhatsAppSkipReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Formaliza, en un solo lugar, la matriz de invariantes de
 * `whatsapp_messages` que hasta ahora solo vivía repartida e implícita entre
 * WhatsAppChannel, MetaCloudApiProvider y WhatsAppWebhookController.
 *
 * Historial de esta matriz — dos rondas de corrección, ninguna copiada tal
 * cual de lo que se propuso, ambas verificadas contra el código real antes
 * de escribir una sola aserción:
 *
 *   1. (ronda anterior) 'failed' y 'unknown' tienen provider_message_id
 *      SIEMPRE null, no "normalmente sí" / "posiblemente sí" —
 *      MetaCloudApiProvider::logAttempt() recibe un `null` literal como
 *      tercer argumento en ambas ramas (catch de excepción y respuesta no
 *      exitosa): no hay ninguna circunstancia en el código donde uno de
 *      estos dos estados pueda tener un id de Meta.
 *   2. (F-23, esta ronda) 'sent' SÍ garantiza provider_message_id no-nulo
 *      — la ronda anterior había documentado la excepción contraria ("sent
 *      puede tener id null si Meta responde 2xx sin messages[]") como un
 *      comportamiento válido ya cubierto por un test existente. Una
 *      revisión posterior señaló, correctamente, que "hay un test que lo
 *      cubre" no demuestra que el comportamiento sea correcto: 'sent' sin
 *      id es operacionalmente inútil (ningún webhook futuro puede
 *      encontrar esa fila) e indistinguible de un envío sano esperando su
 *      webhook. Se corrigió MetaCloudApiProvider::sendTemplate() para
 *      clasificar ese caso como 'unknown' — la ronda anterior de este
 *      archivo tenía, sin saberlo, un bug real disfrazado de invariante
 *      documentada.
 */
class WhatsAppMessageStateInvariantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta_whatsapp.phone_number_id' => '123456',
            'services.meta_whatsapp.access_token' => 'test-token',
            'services.meta_whatsapp.templates.generic_notification' => [
                'name' => 'mova_generic_notification',
                'language' => 'es_PE',
            ],
            'services.meta_whatsapp.app_secret' => 'test-secret',
        ]);
    }

    // ── sent ───────────────────────────────────────────────────────────────

    public function test_sent_has_no_skip_reason_and_no_error(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.sent1']]], 200)]);

        (new MetaCloudApiProvider())->sendTemplate('+51987654321', 'generic_notification', ['x']);

        $message = WhatsAppMessage::where('status', 'sent')->firstOrFail();
        $this->assertNull($message->skip_reason);
        $this->assertNull($message->error);
        $this->assertSame('wamid.sent1', $message->provider_message_id, 'En el caso normal SÍ trae el id de Meta.');
    }

    public function test_sent_always_has_a_non_null_provider_message_id(): void
    {
        // F-23: invariante endurecido esta ronda. 'sent' sin id NO es un
        // estado válido — es 'unknown' (ver el siguiente bloque de tests).
        // Este test fija la garantía positiva: si el status es 'sent', el
        // id de Meta SIEMPRE está presente.
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.sent2']]], 200)]);

        (new MetaCloudApiProvider())->sendTemplate('+51987654321', 'generic_notification', ['x']);

        $message = WhatsAppMessage::where('status', 'sent')->firstOrFail();
        $this->assertNotNull($message->provider_message_id);
    }

    public function test_a_2xx_response_without_a_message_id_is_unknown_not_sent(): void
    {
        // F-23: antes se registraba como 'sent' con id null — sintácticamente
        // válido pero operacionalmente inútil (ningún webhook futuro puede
        // encontrar esta fila). Corregido a 'unknown': Meta respondió éxito
        // HTTP, pero MOVA no puede afirmar qué mensaje es.
        Http::fake(['graph.facebook.com/*' => Http::response([], 200)]);

        $sent = (new MetaCloudApiProvider())->sendTemplate('+51987654321', 'generic_notification', ['x']);

        $this->assertFalse($sent);
        $this->assertDatabaseHas('whatsapp_messages', [
            'status' => 'unknown',
            'provider_message_id' => null,
            'skip_reason' => null,
        ]);
        $this->assertNotNull(WhatsAppMessage::where('status', 'unknown')->latest()->first()->error);
    }

    public function test_a_2xx_without_message_id_does_not_retry_and_is_not_reported_as_a_provider_failure(): void
    {
        // Motivo de negocio explícito: un 2xx sin id PODRÍA significar que
        // Meta sí procesó el envío — reintentar a ciegas arriesgaría un
        // duplicado. Por eso 'unknown', nunca 'failed': isProviderFailure()
        // debe seguir siendo false para este caso, igual que para el timeout.
        Http::fake(['graph.facebook.com/*' => Http::response([], 200)]);

        (new MetaCloudApiProvider())->sendTemplate('+51987654321', 'generic_notification', ['x']);

        $message = WhatsAppMessage::where('status', 'unknown')->firstOrFail();
        $this->assertFalse($message->status->isProviderFailure());
        $this->assertCount(1, Http::recorded(), 'Un solo intento — sin reintento automático.');
    }

    public function test_the_new_unknown_source_is_picked_up_by_the_existing_reconciliation_policy(): void
    {
        // No se cambió mova:reconcile-whatsapp — su política para 'unknown'
        // (esperar el threshold antes de señalar) ya es correcta para
        // cualquier origen del estado, este nuevo entre ellos. Esta prueba
        // confirma que no hace falta tocar WhatsAppReconciliation: cuenta
        // este 'unknown' recién creado como "todavía esperando", no como
        // anomalía inmediata.
        Http::fake(['graph.facebook.com/*' => Http::response([], 200)]);
        (new MetaCloudApiProvider())->sendTemplate('+51987654321', 'generic_notification', ['x']);

        $report = app(\App\Support\WhatsAppReconciliation::class)->run();

        $this->assertTrue($report['healthy'], 'Recién creado, todavía dentro del threshold — no debe ser anomalía.');
        $this->assertSame(1, $report['unknown_still_waiting']);
    }

    public function test_a_webhook_for_an_unrelated_wamid_never_matches_an_existing_unknown_row(): void
    {
        // Edge case pedido explícitamente: una fila 'unknown' (de un 2xx sin
        // id, o de un timeout) tiene provider_message_id = NULL. Si después
        // llega un webhook real de Meta para OTRO mensaje (un wamid legítimo
        // que nunca se registró localmente), el lookup del webhook
        // (`WHERE provider_message_id = $wamid`) no puede encontrar la fila
        // 'unknown' — NULL nunca iguala a un string real en SQL — así que
        // no hay forma de que ese webhook "adivine" o corrompa la fila
        // incierta. Se fija aquí para que quede probado, no solo asumido.
        //
        // Dos resultados distintos, ambos verificados (no basta con el
        // status): (1) la fila 'unknown' queda completamente intacta —
        // ningún campo se toca, incluido updated_at; (2) el webhook no crea
        // ni muta ninguna OTRA fila por accidente — la operación completa es
        // un no-op observable desde fuera, no solo "el status no cambió".
        Http::fake(['graph.facebook.com/*' => Http::response([], 200)]);
        (new MetaCloudApiProvider())->sendTemplate('+51987654321', 'generic_notification', ['x']);
        $unknownMessage = WhatsAppMessage::where('status', 'unknown')->firstOrFail();
        $originalUpdatedAt = $unknownMessage->updated_at;

        $response = $this->postWebhook($this->statusPayload('wamid.unrelated', 'delivered'));

        // (1) La fila 'unknown' no se tocó — ni un campo.
        $unknownMessage->refresh();
        $this->assertSame('unknown', $unknownMessage->status->value, 'La fila incierta no debe verse afectada por un webhook de otro mensaje.');
        $this->assertNull($unknownMessage->provider_message_id);
        $this->assertNull($unknownMessage->skip_reason);
        $this->assertTrue(
            $originalUpdatedAt->equalTo($unknownMessage->updated_at),
            'updated_at no debe cambiar: ni un solo campo de esta fila debió tocarse.'
        );

        // (2) La operación global es un no-op observable: sigue habiendo
        // exactamente 1 fila (la 'unknown' de antes), el webhook responde
        // 200 igual (Meta no debe ver un error por un mensaje que MOVA no
        // reconoce — mismo comportamiento que un wamid genuinamente
        // desconocido), y no aparece ninguna fila nueva con el wamid del evento.
        $response->assertStatus(200);
        $this->assertDatabaseCount('whatsapp_messages', 1);
        $this->assertDatabaseMissing('whatsapp_messages', ['provider_message_id' => 'wamid.unrelated']);
    }

    public function test_client_reference_never_decides_which_row_a_webhook_updates(): void
    {
        // Blindaje del invariante documentado en la migración y en
        // WhatsAppWebhookController: client_reference NO es único (varios
        // recordatorios de la MISMA lección comparten el mismo valor), así
        // que jamás puede ser lo que decide qué fila actualiza un webhook.
        // Dos filas 'sent' con idéntico client_reference, distinto wamid: el
        // webhook debe actualizar únicamente la que coincide por
        // provider_message_id, sin importar que ambas "parezcan" la misma
        // notificación desde el punto de vista de soporte.
        $messageA = WhatsAppMessage::create([
            'to' => '+51987654321',
            'template_key' => 'generic_notification',
            'client_reference' => 'ClassReminderNotification#123',
            'provider' => 'meta',
            'provider_message_id' => 'wamid.A',
            'status' => 'sent',
        ]);
        $messageB = WhatsAppMessage::create([
            'to' => '+51987654321',
            'template_key' => 'generic_notification',
            'client_reference' => 'ClassReminderNotification#123', // idéntico a propósito
            'provider' => 'meta',
            'provider_message_id' => 'wamid.B',
            'status' => 'sent',
        ]);

        $this->postWebhook($this->statusPayload('wamid.B', 'delivered'));

        $messageA->refresh();
        $messageB->refresh();
        $this->assertSame('sent', $messageA->status->value, 'A no debe verse afectada — el webhook era para B.');
        $this->assertSame('delivered', $messageB->status->value, 'B es la que corresponde por provider_message_id.');
    }

    // ── failed / unknown ─────────────────────────────────────────────────

    public function test_failed_always_has_a_null_provider_message_id_and_a_non_null_error(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'rejected']], 400)]);

        (new MetaCloudApiProvider())->sendTemplate('+51987654321', 'generic_notification', ['x']);

        $message = WhatsAppMessage::where('status', 'failed')->firstOrFail();
        $this->assertNull($message->provider_message_id);
        $this->assertNull($message->skip_reason);
        $this->assertNotNull($message->error);
    }

    public function test_unknown_always_has_a_null_provider_message_id_and_a_non_null_error(): void
    {
        Http::fake(['graph.facebook.com/*' => function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        }]);

        (new MetaCloudApiProvider())->sendTemplate('+51987654321', 'generic_notification', ['x']);

        $message = WhatsAppMessage::where('status', 'unknown')->firstOrFail();
        $this->assertNull($message->provider_message_id);
        $this->assertNull($message->skip_reason);
        $this->assertNotNull($message->error);
    }

    // ── delivered / read ──────────────────────────────────────────────────
    //
    // Solo alcanzables actualizando una fila 'sent' ya existente vía webhook
    // (WhatsAppWebhookController busca por provider_message_id) — nunca se
    // crean desde cero con estos estados.

    public function test_delivered_keeps_the_provider_message_id_and_stays_without_skip_reason_or_error(): void
    {
        $message = WhatsAppMessage::create([
            'to' => '+51987654321',
            'template_key' => 'generic_notification',
            'provider' => 'meta',
            'provider_message_id' => 'wamid.deliv1',
            'status' => 'sent',
        ]);

        $this->postWebhook($this->statusPayload('wamid.deliv1', 'delivered'));

        $message->refresh();
        $this->assertSame('delivered', $message->status->value);
        $this->assertSame('wamid.deliv1', $message->provider_message_id);
        $this->assertNull($message->skip_reason);
        $this->assertNull($message->error);
    }

    public function test_read_keeps_the_provider_message_id_and_stays_without_skip_reason_or_error(): void
    {
        $message = WhatsAppMessage::create([
            'to' => '+51987654321',
            'template_key' => 'generic_notification',
            'provider' => 'meta',
            'provider_message_id' => 'wamid.read1',
            'status' => 'delivered',
        ]);

        $this->postWebhook($this->statusPayload('wamid.read1', 'read'));

        $message->refresh();
        $this->assertSame('read', $message->status->value);
        $this->assertSame('wamid.read1', $message->provider_message_id);
        $this->assertNull($message->skip_reason);
        $this->assertNull($message->error);
    }

    // ── skipped ───────────────────────────────────────────────────────────

    public function test_skipped_always_has_a_non_null_skip_reason_a_null_error_and_a_null_provider_message_id(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('parent', 'web');
        config(['services.whatsapp.enabled' => true, 'services.whatsapp.require_verified' => true]);

        $user = $this->verifiedUser();
        $user->optOutOfWhatsApp();

        $user->fresh()->notify(new \App\Notifications\ClassReminderNotification($this->stubLesson(), '24h'));

        $message = WhatsAppMessage::where('status', 'skipped')->firstOrFail();
        $this->assertNotNull($message->skip_reason);
        $this->assertInstanceOf(WhatsAppSkipReason::class, $message->skip_reason);
        $this->assertNull($message->error);
        $this->assertNull($message->provider_message_id);
    }

    // ── Helpers (mismo patrón que WhatsAppConsentTest, repetido aquí para
    // que este archivo no dependa de otro test class) ─────────────────────

    private function verifiedUser(array $attributes = []): \App\Models\User
    {
        $normalized = $attributes['phone_verified_normalized'] ?? '+51900000098';
        unset($attributes['phone_verified_normalized']);

        $user = \App\Models\User::factory()->create(array_merge([
            'password' => 'password',
            'phone' => '987654322',
            'phone_verified_at' => now(),
            'whatsapp_opt_in_at' => now(),
        ], $attributes));
        $user->assignRole('parent');
        $user->phone_verified_normalized = $normalized;
        $user->save();

        return $user->fresh();
    }

    private function stubLesson(): \App\Models\Lesson
    {
        $subject = \App\Models\Subject::create([
            'name' => 'Materia '.fake()->unique()->numerify('####'),
            'level' => 'secundaria',
        ]);
        $teacher = \App\Models\User::factory()->create();
        $profile = \App\Models\TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_verified' => true,
            'credits_available' => 0,
            'credits_reserved' => 0,
        ]);
        $profile->subjects()->attach($subject->id);

        $parent = \App\Models\User::factory()->create();
        $student = \App\Models\Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Alumno',
            'last_name' => 'Prueba',
            'grade_level' => 'secundaria',
        ]);

        return \App\Models\Lesson::create([
            'teacher_profile_id' => $profile->id,
            'student_id' => $student->id,
            'start_time' => now()->addHours(24),
            'duration_minutes' => 60,
            'status' => 'scheduled',
        ]);
    }

    private function statusPayload(string $wamid, string $status, ?int $timestamp = null): array
    {
        $entry = ['id' => $wamid, 'status' => $status];
        if ($timestamp !== null) {
            $entry['timestamp'] = (string) $timestamp;
        }

        return ['entry' => [['changes' => [['value' => ['statuses' => [$entry]]]]]]];
    }

    private function postWebhook(array $payload): TestResponse
    {
        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, config('services.meta_whatsapp.app_secret'));

        return $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'HTTP_X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }
}
