<?php

namespace Tests\Feature;

use App\Jobs\ProcessMercadoPagoWebhook;
use App\Models\PaymentWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Prueba el endpoint HTTP completo (routes/api.php →
 * MercadoPagoWebhookController) — firma, dedupe, y que el trabajo
 * financiero NUNCA corre dentro del ciclo de la request (Queue::fake()
 * intercepta el dispatch; ProcessMercadoPagoWebhookJobTest cubre lo que
 * hace el job en sí).
 */
class MercadoPagoWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'unit-test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        // WEBHOOK ENABLEMENT (ronda de hardening distribuido): todos los
        // tests de este archivo asumen el webhook YA configurado/activo —
        // el comportamiento con el flag en false (default real de
        // config/payments.php) se cubre aparte, ver
        // test_webhooks_disabled_rejects_everything_regardless_of_signature().
        config([
            'payments.mercadopago.webhook_secret' => self::SECRET,
            'payments.mercadopago.webhooks_enabled' => true,
        ]);
    }

    public function test_valid_new_notification_returns_200_persists_and_dispatches_job(): void
    {
        Queue::fake();

        $response = $this->postJson(
            '/api/webhooks/mercadopago?data_id=ORD-ABC',
            ['id' => 'evt-1', 'action' => 'payment.updated', 'type' => 'payment', 'data' => ['id' => 'ORD-ABC']],
            $this->validSignatureHeaders('ORD-ABC')
        );

        $response->assertStatus(200);
        $this->assertDatabaseHas('payment_webhooks', [
            'provider' => 'mercadopago',
            'event_id' => 'evt-1',
            'status' => 'received',
        ]);

        $webhook = PaymentWebhook::where('event_id', 'evt-1')->firstOrFail();
        Queue::assertPushed(ProcessMercadoPagoWebhook::class, fn ($job) => $job->paymentWebhookId === $webhook->id);
    }

    public function test_duplicate_notification_is_idempotent_200_and_dispatches_the_job_only_once(): void
    {
        Queue::fake();

        $payload = ['id' => 'evt-dup', 'data' => ['id' => 'ORD-ABC']];
        $headers = $this->validSignatureHeaders('ORD-ABC');

        $first = $this->postJson('/api/webhooks/mercadopago?data_id=ORD-ABC', $payload, $headers);
        $second = $this->postJson('/api/webhooks/mercadopago?data_id=ORD-ABC', $payload, $headers);

        $first->assertStatus(200);
        $second->assertStatus(200);
        $this->assertSame(1, PaymentWebhook::where('event_id', 'evt-dup')->count());
        Queue::assertPushed(ProcessMercadoPagoWebhook::class, 1);
    }

    public function test_invalid_signature_returns_401_and_persists_nothing(): void
    {
        Queue::fake();

        $headers = $this->validSignatureHeaders('ORD-ABC');
        $headers['X-Signature'] = 'ts=1700000000000,v1='.str_repeat('0', 64);

        $response = $this->postJson(
            '/api/webhooks/mercadopago?data_id=ORD-ABC',
            ['id' => 'evt-bad-sig', 'data' => ['id' => 'ORD-ABC']],
            $headers
        );

        $response->assertStatus(401);
        $this->assertDatabaseCount('payment_webhooks', 0);
        Queue::assertNothingPushed();
    }

    public function test_missing_signature_header_returns_401(): void
    {
        Queue::fake();

        $response = $this->postJson(
            '/api/webhooks/mercadopago?data_id=ORD-ABC',
            ['id' => 'evt-no-sig', 'data' => ['id' => 'ORD-ABC']]
        );

        $response->assertStatus(401);
        $this->assertDatabaseCount('payment_webhooks', 0);
        Queue::assertNothingPushed();
    }

    public function test_unconfigured_webhook_secret_rejects_everything(): void
    {
        config(['payments.mercadopago.webhook_secret' => null]);
        Queue::fake();

        $response = $this->postJson(
            '/api/webhooks/mercadopago?data_id=ORD-ABC',
            ['id' => 'evt-no-secret', 'data' => ['id' => 'ORD-ABC']],
            $this->validSignatureHeaders('ORD-ABC')
        );

        $response->assertStatus(401);
        Queue::assertNothingPushed();
    }

    public function test_valid_signature_but_missing_root_id_returns_400_without_persisting(): void
    {
        Queue::fake();

        $response = $this->postJson(
            '/api/webhooks/mercadopago?data_id=ORD-ABC',
            ['data' => ['id' => 'ORD-ABC']], // sin 'id' raíz
            $this->validSignatureHeaders('ORD-ABC')
        );

        $response->assertStatus(400);
        $this->assertDatabaseCount('payment_webhooks', 0);
        Queue::assertNothingPushed();
    }

    public function test_valid_signature_but_missing_data_id_returns_400_without_persisting(): void
    {
        Queue::fake();

        $response = $this->postJson(
            '/api/webhooks/mercadopago?data_id=',
            ['id' => 'evt-no-data-id'], // sin data.id
            $this->validSignatureHeaders(null)
        );

        $response->assertStatus(400);
        $this->assertDatabaseCount('payment_webhooks', 0);
        Queue::assertNothingPushed();
    }

    public function test_notification_with_unexpected_type_returns_400(): void
    {
        Queue::fake();

        $response = $this->postJson(
            '/api/webhooks/mercadopago?data_id=ORD-ABC',
            ['id' => 'evt-wrong-type', 'type' => 'topic_chargebacks_wh', 'data' => ['id' => 'ORD-ABC']],
            $this->validSignatureHeaders('ORD-ABC')
        );

        $response->assertStatus(400);
        $this->assertDatabaseCount('payment_webhooks', 0);
        Queue::assertNothingPushed();
    }

    public function test_notification_with_live_mode_mismatch_returns_400(): void
    {
        config(['payments.mercadopago.expected_live_mode' => false]);
        Queue::fake();

        $response = $this->postJson(
            '/api/webhooks/mercadopago?data_id=ORD-ABC',
            ['id' => 'evt-wrong-env', 'type' => 'payment', 'live_mode' => true, 'data' => ['id' => 'ORD-ABC']],
            $this->validSignatureHeaders('ORD-ABC')
        );

        $response->assertStatus(400);
        $this->assertDatabaseCount('payment_webhooks', 0);
        Queue::assertNothingPushed();
    }

    /**
     * WEBHOOK ENABLEMENT: en false (default real de config/payments.php),
     * el endpoint rechaza CUALQUIER notificación entrante — incluso una con
     * firma perfectamente válida — sin persistir nada ni encolar nada.
     * webhook_secret sigue configurado aquí a propósito: demuestra que el
     * rechazo es por el flag, no por falta de secreto (ver
     * test_unconfigured_webhook_secret_rejects_everything() para ese caso).
     */
    public function test_webhooks_disabled_rejects_everything_regardless_of_signature(): void
    {
        config(['payments.mercadopago.webhooks_enabled' => false]);
        Queue::fake();

        $response = $this->postJson(
            '/api/webhooks/mercadopago?data_id=ORD-ABC',
            ['id' => 'evt-disabled', 'action' => 'payment.updated', 'type' => 'payment', 'data' => ['id' => 'ORD-ABC']],
            $this->validSignatureHeaders('ORD-ABC')
        );

        $response->assertStatus(404);
        $this->assertDatabaseCount('payment_webhooks', 0);
        Queue::assertNothingPushed();
    }

    public function test_payload_too_large_is_rejected(): void
    {
        Queue::fake();

        $response = $this->call(
            'POST',
            '/api/webhooks/mercadopago?data_id=ORD-ABC',
            server: $this->transformHeadersToServerVars($this->validSignatureHeaders('ORD-ABC')),
            content: str_repeat('a', 1_000_001)
        );

        $response->assertStatus(413);
        $this->assertDatabaseCount('payment_webhooks', 0);
    }

    /**
     * Firma REALMENTE válida para $dataId — el algoritmo en sí ya está
     * cubierto por MercadoPagoWebhookSignatureVerifierTest; aquí interesa
     * probar el endpoint end-to-end.
     */
    private function validSignatureHeaders(?string $dataId): array
    {
        $ts = '1700000000000';
        $requestId = 'req-123';

        $manifest = '';
        if ($dataId !== null && $dataId !== '') {
            $manifest .= 'id:'.strtolower($dataId).';';
        }
        $manifest .= 'request-id:'.$requestId.';';
        $manifest .= 'ts:'.$ts.';';

        return [
            'X-Signature' => 'ts='.$ts.',v1='.hash_hmac('sha256', $manifest, self::SECRET),
            'X-Request-Id' => $requestId,
        ];
    }
}
