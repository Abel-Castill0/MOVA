<?php

namespace Tests\Feature;

use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Cubre el webhook de Meta diseñado en esta ronda — deliberadamente sin
 * activar contra tráfico real (ver docs/whatsapp-architecture.md). Todas
 * las pruebas confirman que, sin credenciales configuradas, la ruta
 * rechaza todo por defecto; y que con credenciales de PRUEBA sí verifica
 * correctamente la firma/handshake antes de aceptar nada — sobre el RAW
 * body, no sobre un JSON reconstruido (ver test_handle_signature_is_computed_over_the_raw_body).
 */
class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.meta_whatsapp.app_secret' => 'test-secret']);
    }

    // ── GET verify (handshake de configuración en el panel de Meta) ────────

    public function test_verify_rejects_when_no_token_is_configured(): void
    {
        config(['services.meta_whatsapp.webhook_verify_token' => null]);

        $this->get('/api/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=anything&hub_challenge=xyz')
            ->assertStatus(403);
    }

    public function test_verify_rejects_wrong_token(): void
    {
        config(['services.meta_whatsapp.webhook_verify_token' => 'correct-token']);

        $this->get('/api/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=wrong-token&hub_challenge=xyz')
            ->assertStatus(403);
    }

    public function test_verify_accepts_correct_token_and_echoes_challenge(): void
    {
        config(['services.meta_whatsapp.webhook_verify_token' => 'correct-token']);

        $response = $this->get('/api/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=correct-token&hub_challenge=xyz789');

        $response->assertStatus(200);
        $this->assertSame('xyz789', $response->getContent());
    }

    public function test_verify_rejects_missing_params(): void
    {
        config(['services.meta_whatsapp.webhook_verify_token' => 'correct-token']);

        // Meta no distingue "faltan parámetros" de "token incorrecto" en su
        // propio contrato — ambos casos son simplemente "no verificado".
        $this->get('/api/webhooks/whatsapp')->assertStatus(403);
    }

    // ── POST handle: seguridad de la firma ──────────────────────────────────

    public function test_handle_rejects_without_a_configured_app_secret(): void
    {
        config(['services.meta_whatsapp.app_secret' => null]);

        $this->postJson('/api/webhooks/whatsapp', ['entry' => []])
            ->assertStatus(403);
    }

    public function test_handle_rejects_an_invalid_signature(): void
    {
        $this->postJson('/api/webhooks/whatsapp', ['entry' => []], [
            'X-Hub-Signature-256' => 'sha256=deadbeef',
        ])->assertStatus(403);
    }

    public function test_handle_accepts_a_valid_signature_and_returns_200(): void
    {
        $this->postWebhook(['entry' => []])->assertStatus(200);
    }

    /**
     * La firma se calcula sobre el body CRUDO, no sobre un JSON
     * reconstruido a partir de $request->all() — si se recalculara el JSON
     * (con distinto orden de claves/espaciado), una firma legítima de Meta
     * dejaría de coincidir. Esta prueba arma un body con espaciado no
     * estándar (distinto de json_encode() por defecto) y confirma que
     * sigue validando correctamente porque WhatsAppWebhookController usa
     * $request->getContent() tal cual.
     */
    public function test_handle_signature_is_computed_over_the_raw_body(): void
    {
        $body = '{"entry":[],  "extra_spacing": true}'; // espaciado no canónico a propósito
        $signature = 'sha256='.hash_hmac('sha256', $body, 'test-secret');

        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'HTTP_X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(200);
    }

    public function test_handle_rejects_a_payload_over_the_size_limit(): void
    {
        $body = json_encode(['entry' => [], 'padding' => str_repeat('x', 1_000_001)]);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'test-secret');

        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'HTTP_X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(413);

        $this->assertDatabaseCount('whatsapp_messages', 0);
    }

    // ── POST handle: procesamiento de estados ───────────────────────────────

    public function test_handle_updates_delivered_status_for_a_known_message(): void
    {
        $this->makeMessage('wamid.known', 'sent');

        $this->postWebhook($this->statusPayload('wamid.known', 'delivered'))->assertStatus(200);

        $this->assertDatabaseHas('whatsapp_messages', [
            'provider_message_id' => 'wamid.known',
            'status' => 'delivered',
        ]);
    }

    public function test_handle_ignores_a_status_for_an_unknown_message_without_erroring(): void
    {
        $this->postWebhook($this->statusPayload('wamid.unknown', 'read'))->assertStatus(200);

        $this->assertDatabaseCount('whatsapp_messages', 0);
    }

    // ── Orden y no-regresión de estado (Meta no garantiza orden temporal) ──

    public function test_sent_can_advance_to_delivered(): void
    {
        $message = $this->makeMessage('wamid.1', 'sent');
        $this->postWebhook($this->statusPayload('wamid.1', 'delivered'));
        $this->assertSame('delivered', $message->fresh()->status->value);
    }

    public function test_delivered_can_advance_to_read(): void
    {
        $message = $this->makeMessage('wamid.2', 'delivered');
        $this->postWebhook($this->statusPayload('wamid.2', 'read'));
        $this->assertSame('read', $message->fresh()->status->value);
    }

    public function test_read_does_not_regress_to_delivered(): void
    {
        $message = $this->makeMessage('wamid.3', 'read');
        $this->postWebhook($this->statusPayload('wamid.3', 'delivered'));
        $this->assertSame('read', $message->fresh()->status->value);
    }

    public function test_delivered_does_not_regress_to_sent(): void
    {
        $message = $this->makeMessage('wamid.4', 'delivered');
        $this->postWebhook($this->statusPayload('wamid.4', 'sent'));
        $this->assertSame('delivered', $message->fresh()->status->value);
    }

    public function test_duplicated_event_is_a_no_op(): void
    {
        $message = $this->makeMessage('wamid.5', 'delivered');
        $this->postWebhook($this->statusPayload('wamid.5', 'delivered'));
        $this->assertSame('delivered', $message->fresh()->status->value);
    }

    public function test_out_of_order_delivery_timestamps_do_not_regress_status(): void
    {
        // 'read' llega primero (evento más nuevo), luego 'delivered' llega
        // tarde (evento más viejo, timestamp anterior) — el estado no debe
        // retroceder aunque el evento tardío tenga su propio timestamp real.
        $message = $this->makeMessage('wamid.6', 'sent');
        $this->postWebhook($this->statusPayload('wamid.6', 'read', now()->subMinute()->timestamp));
        $this->postWebhook($this->statusPayload('wamid.6', 'delivered', now()->subMinutes(5)->timestamp));

        $this->assertSame('read', $message->fresh()->status->value);
    }

    /**
     * Escenario literal de la revisión: 'delivered' con timestamp POSTERIOR
     * a un 'read' ya aplicado tampoco debe retroceder el estado — el rango
     * semántico (delivered < read) manda, nunca el timestamp del evento por
     * sí solo. Deliberadamente distinto del test de arriba (ahí 'delivered'
     * llega con timestamp ANTERIOR; aquí con timestamp POSTERIOR) para
     * dejar explícito que el timestamp nunca es lo que decide.
     */
    public function test_a_later_timestamp_does_not_override_a_lower_semantic_rank(): void
    {
        $message = $this->makeMessage('wamid.10', 'sent');
        $this->postWebhook($this->statusPayload('wamid.10', 'read', now()->timestamp));
        $this->postWebhook($this->statusPayload('wamid.10', 'delivered', now()->addMinute()->timestamp));

        $this->assertSame('read', $message->fresh()->status->value);
    }

    /**
     * Distinto del test de "duplicated event" de arriba: aquí Meta reenvía
     * la MISMA entrega de webhook completa (mismo wamid+status+timestamp,
     * como reintento real por no haber recibido 200 a tiempo) — se corta en
     * whatsapp_webhook_events ANTES de llegar siquiera a evaluar el rango,
     * no solo porque el rango ya esté cubierto.
     */
    public function test_a_redelivered_identical_webhook_event_is_recorded_only_once(): void
    {
        $timestamp = now()->timestamp;
        $this->makeMessage('wamid.11', 'sent');

        $this->postWebhook($this->statusPayload('wamid.11', 'delivered', $timestamp));
        $this->postWebhook($this->statusPayload('wamid.11', 'delivered', $timestamp));

        $this->assertDatabaseCount('whatsapp_webhook_events', 1);
    }

    public function test_failed_does_not_apply_after_delivered(): void
    {
        $message = $this->makeMessage('wamid.7', 'delivered');
        $this->postWebhook($this->statusPayload('wamid.7', 'failed'));
        $this->assertSame('delivered', $message->fresh()->status->value);
    }

    public function test_failed_applies_over_sent(): void
    {
        $message = $this->makeMessage('wamid.8', 'sent');
        $this->postWebhook($this->statusPayload('wamid.8', 'failed'));
        $this->assertSame('failed', $message->fresh()->status->value);
    }

    public function test_status_is_terminal_once_failed(): void
    {
        $message = $this->makeMessage('wamid.9', 'failed');
        $this->postWebhook($this->statusPayload('wamid.9', 'delivered'));
        $this->assertSame('failed', $message->fresh()->status->value);
    }

    // ── 'skipped' es terminal igual que 'failed', no un "rango 0" ──────────
    //
    // En la práctica ningún webhook real puede llegar a alcanzar esta fila:
    // un mensaje 'skipped' siempre tiene provider_message_id = null (MOVA
    // nunca llamó a Meta), y el lookup del webhook busca por
    // provider_message_id = <wamid real>, que nunca iguala a NULL en SQL.
    // Pero shouldApply() calculaba el rango de 'skipped' como
    // deliveryRank() ?? 0 — el mismo valor que 'unknown', que SÍ debe ser
    // superable por cualquier evento real. Eso trataba a 'skipped' como
    // "siempre reemplazable" en vez de terminal, protegido solo por
    // accidente (el null de provider_message_id), no por diseño. Estos
    // tests fuerzan el escenario vía makeMessage() con un wamid real —
    // deliberadamente hipotético, para blindar shouldApply() en sí misma
    // aunque la ruta de entrada esté cerrada por otro lado.
    public function test_skipped_does_not_advance_to_delivered(): void
    {
        $message = $this->makeMessage('wamid.20', 'skipped');
        $this->postWebhook($this->statusPayload('wamid.20', 'delivered'));
        $this->assertSame('skipped', $message->fresh()->status->value);
    }

    public function test_skipped_does_not_advance_to_read(): void
    {
        $message = $this->makeMessage('wamid.21', 'skipped');
        $this->postWebhook($this->statusPayload('wamid.21', 'read'));
        $this->assertSame('skipped', $message->fresh()->status->value);
    }

    public function test_skipped_does_not_advance_to_sent(): void
    {
        // Distinto camino de protección: 'sent' entrante se ignora siempre
        // en recordStatus() antes incluso de llegar a shouldApply() (ya se
        // registra al enviar) — no ejercita el rango, pero cierra el tercer
        // caso que pidió explícitamente la revisión (skipped → sent = imposible).
        $message = $this->makeMessage('wamid.22', 'skipped');
        $this->postWebhook($this->statusPayload('wamid.22', 'sent'));
        $this->assertSame('skipped', $message->fresh()->status->value);
    }

    public function test_skipped_does_not_advance_to_failed(): void
    {
        // Cuarto y último caso del invariante formal: 'skipped' es terminal
        // ante CUALQUIER evento entrante por webhook, incluido 'failed' — el
        // guard en shouldApply() (`$current === Skipped`) no distingue por
        // el valor de $incoming, a diferencia del guard de 'failed' (que sí
        // deja pasar un 'failed' repetido sobre sí mismo). Esto es
        // deliberado: 'skipped' fue una decisión de MOVA, no un intento
        // fallido — Meta nunca se enteró de este mensaje, así que no puede
        // "fallarlo" retroactivamente. Una eventual corrección administrativa
        // manual (no automática, no vía webhook) queda fuera de este
        // invariante — este test cubre solo la vía automática de Meta.
        $message = $this->makeMessage('wamid.23', 'skipped');
        $this->postWebhook($this->statusPayload('wamid.23', 'failed'));
        $this->assertSame('skipped', $message->fresh()->status->value);
    }

    private function makeMessage(string $wamid, string $status): WhatsAppMessage
    {
        return WhatsAppMessage::create([
            'to' => '+51987654321',
            'template_key' => 'generic_notification',
            'provider' => 'meta',
            'provider_message_id' => $wamid,
            'status' => $status,
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
