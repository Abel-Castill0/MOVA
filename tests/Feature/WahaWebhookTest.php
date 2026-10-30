<?php

namespace Tests\Feature;

use App\Models\WhatsAppMessage;
use App\WhatsApp\Waha\WahaSessionState;
use App\WhatsApp\WhatsAppMessageStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Webhook de WAHA: autenticación HMAC-SHA512 del cuerpo bruto, confirmación de entrega idempotente y sin retrocesos,
 * y estado de sesión. WAHA no toca pagos ni créditos desde aquí.
 */
class WahaWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'hmac-secret-for-tests-0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['services.waha.hmac_key' => self::KEY]);
    }

    private function message(string $id = '3EB0FFFF7777', WhatsAppMessageStatus $status = WhatsAppMessageStatus::Sent): WhatsAppMessage
    {
        return WhatsAppMessage::create([
            'to' => '51987654321', 'template_key' => 'generic_notification', 'client_reference' => 'T#1', 'provider' => 'waha',
            'provider_message_id' => $id, 'status' => $status, 'status_updated_at' => now(),
        ]);
    }

    private function ack(string $ackName, string $id = 'true_51987654321@c.us_3EB0FFFF7777', array $extra = []): array
    {
        return array_replace_recursive([
            'event' => 'message.ack', 'session' => 'default', 'engine' => 'NOWEB', 'timestamp' => now()->getTimestampMs(),
            'payload' => ['id' => $id, 'fromMe' => true, 'ack' => 2, 'ackName' => $ackName],
        ], $extra);
    }

    private function hook(array $body, array $headers = [], ?string $key = self::KEY)
    {
        $raw = json_encode($body);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($key !== null) {
            $server['HTTP_X_WEBHOOK_HMAC'] = hash_hmac('sha512', $raw, $key);
            $server['HTTP_X_WEBHOOK_HMAC_ALGORITHM'] = 'sha512';
        }
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', '/api/webhooks/waha', [], [], [], $server, $raw);
    }

    public function test_without_a_configured_key_everything_is_rejected(): void
    {
        config(['services.waha.hmac_key' => null]);
        $this->message();

        $this->hook($this->ack('DEVICE'))->assertForbidden();

        $this->assertSame(WhatsAppMessageStatus::Sent, WhatsAppMessage::firstOrFail()->status);
    }

    public function test_missing_or_wrong_signature_is_rejected(): void
    {
        $this->message();

        $this->hook($this->ack('DEVICE'), [], null)->assertForbidden();
        $this->hook($this->ack('DEVICE'), [], 'otra-clave')->assertForbidden();
        $this->hook($this->ack('DEVICE'), ['X-Webhook-Hmac-Algorithm' => 'sha256'])->assertForbidden();

        $this->assertSame(WhatsAppMessageStatus::Sent, WhatsAppMessage::firstOrFail()->status);
    }

    public function test_a_tampered_body_does_not_pass_the_signature(): void
    {
        $this->message();
        $body = $this->ack('DEVICE');
        $signature = hash_hmac('sha512', json_encode($body), self::KEY);

        $tampered = $this->ack('READ');
        $this->call('POST', '/api/webhooks/waha', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_HMAC' => $signature, 'HTTP_X_WEBHOOK_HMAC_ALGORITHM' => 'sha512',
        ], json_encode($tampered))->assertForbidden();
    }

    public function test_an_ack_that_arrives_before_the_message_id_is_not_consumed(): void
    {
        $this->hook($this->ack('DEVICE'))->assertOk();
        $this->assertDatabaseCount('whatsapp_webhook_events', 0);

        $this->message();
        $this->hook($this->ack('DEVICE'))->assertOk(); // el reintento de WAHA sí se aplica

        $this->assertSame(WhatsAppMessageStatus::Delivered, WhatsAppMessage::firstOrFail()->status);
    }

    public function test_device_ack_marks_delivered_and_read_marks_read(): void
    {
        $this->message();

        $this->hook($this->ack('DEVICE'))->assertOk();
        $row = WhatsAppMessage::firstOrFail();
        $this->assertSame(WhatsAppMessageStatus::Delivered, $row->status);
        $this->assertNotNull($row->delivered_at);

        $this->hook($this->ack('READ'))->assertOk();
        $row->refresh();
        $this->assertSame(WhatsAppMessageStatus::Read, $row->status);
        $this->assertNotNull($row->read_at);
    }

    public function test_a_repeated_event_is_idempotent_and_an_old_ack_never_regresses_the_state(): void
    {
        $this->message();

        $this->hook($this->ack('READ'))->assertOk();
        $this->hook($this->ack('READ'))->assertOk();
        $this->hook($this->ack('DEVICE'))->assertOk(); // llega tarde: no retrocede

        $this->assertSame(WhatsAppMessageStatus::Read, WhatsAppMessage::firstOrFail()->status);
        $this->assertDatabaseCount('whatsapp_webhook_events', 2);
    }

    public function test_error_ack_marks_failed_unless_already_delivered(): void
    {
        $this->message('3EB0AAAA0001');
        $this->message('3EB0AAAA0002', WhatsAppMessageStatus::Delivered);

        $this->hook($this->ack('ERROR', 'true_x_3EB0AAAA0001'))->assertOk();
        $this->hook($this->ack('ERROR', 'true_x_3EB0AAAA0002'))->assertOk();

        $this->assertSame(WhatsAppMessageStatus::Failed, WhatsAppMessage::where('provider_message_id', '3EB0AAAA0001')->firstOrFail()->status);
        $this->assertSame(WhatsAppMessageStatus::Delivered, WhatsAppMessage::where('provider_message_id', '3EB0AAAA0002')->firstOrFail()->status);
    }

    public function test_server_and_pending_acks_and_acks_for_foreign_messages_change_nothing(): void
    {
        $this->message();

        $this->hook($this->ack('SERVER'))->assertOk();
        $this->hook($this->ack('PENDING'))->assertOk();
        $this->hook($this->ack('READ', 'true_x_3EB0FFFF7777', ['payload' => ['fromMe' => false]]))->assertOk();
        $this->hook($this->ack('READ', 'true_x_3EB0DESCONOCIDO'))->assertOk(); // mensaje que MOVA no envió

        $this->assertSame(WhatsAppMessageStatus::Sent, WhatsAppMessage::firstOrFail()->status);
    }

    public function test_it_only_correlates_waha_messages_not_other_providers(): void
    {
        $meta = WhatsAppMessage::create([
            'to' => '51987654321', 'template_key' => 'generic_notification', 'provider' => 'meta',
            'provider_message_id' => '3EB0FFFF7777', 'status' => WhatsAppMessageStatus::Sent, 'status_updated_at' => now(),
        ]);

        $this->hook($this->ack('READ'))->assertOk();

        $this->assertSame(WhatsAppMessageStatus::Sent, $meta->fresh()->status);
    }

    public function test_stale_timestamp_is_rejected(): void
    {
        $this->message();

        $this->hook($this->ack('DEVICE'), ['X-Webhook-Timestamp' => (string) now()->subDays(2)->getTimestampMs()])->assertForbidden();
        $this->hook($this->ack('DEVICE'), ['X-Webhook-Timestamp' => (string) now()->getTimestampMs()])->assertOk();
    }

    public function test_oversized_body_is_rejected(): void
    {
        $this->hook(['event' => 'message.ack', 'padding' => str_repeat('a', 300_000)])->assertStatus(413);
    }

    public function test_session_status_updates_the_cached_state(): void
    {
        $this->hook(['event' => 'session.status', 'payload' => ['status' => 'FAILED']])->assertOk();
        $this->assertSame('FAILED', WahaSessionState::status());

        $this->hook(['event' => 'session.status', 'payload' => ['status' => 'WORKING']])->assertOk();
        $this->assertSame('WORKING', WahaSessionState::status());
    }

    public function test_whatsapp_restrictions_pause_sending(): void
    {
        $until = now()->addHours(5)->getTimestamp();

        $this->hook(['event' => 'session.status', 'payload' => ['status' => 'WORKING', 'data' => ['reachoutTimelock' => ['isActive' => true, 'timeEnforcementEnds' => $until]]]])->assertOk();

        $this->assertNotNull(WahaSessionState::pausedUntil());
        $this->assertEqualsWithDelta($until, WahaSessionState::pausedUntil()->getTimestamp(), 2);
    }

    public function test_other_events_are_accepted_and_ignored(): void
    {
        $this->hook(['event' => 'message', 'payload' => ['id' => 'x', 'body' => 'hola']])->assertOk();
        $this->assertDatabaseCount('whatsapp_messages', 0);
    }
}
