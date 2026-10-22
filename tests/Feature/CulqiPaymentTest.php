<?php

namespace Tests\Feature;

use App\Jobs\ProcessCulqiWebhook;
use App\Models\CreditTransaction;
use App\Models\PaymentOrder;
use App\Models\PaymentWebhook;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Payment\Contracts\CardPaymentInstrument;
use App\Payment\Contracts\PaymentProviderContract;
use App\Payment\Contracts\YapePaymentInstrument;
use App\Payment\Culqi\CulqiChargeMapper;
use App\Payment\Culqi\CulqiClient;
use App\Payment\Culqi\CulqiConfig;
use App\Payment\CulqiPaymentProvider;
use App\Services\CulqiPaymentReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Culqi: cargos (confirmado / rechazado / 3DS / incierto), webhooks (duplicados,
 * secreto, verdad server-to-server), devoluciones y la garantía central:
 * una recarga solo acredita créditos UNA vez. Las respuestas simuladas
 * reproducen la forma REAL observada en el sandbox de Culqi (2026-10-22), no la
 * de la documentación OpenAPI (p. ej. venta exitosa con paid=false).
 */
class CulqiPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_0123456789abcdef0123456789abcdef01234567';

    private int $chargePosts = 0;

    private int $chargeGets = 0;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        config([
            'payments.enabled' => true,
            'payments.provider' => 'culqi',
            'payments.culqi.env' => 'test',
            'payments.culqi.public_key' => 'pk_test_AbCdEf0123456789',
            'payments.culqi.private_key' => 'sk_test_AbCdEf0123456789',
            'payments.culqi.webhook_secret' => self::WEBHOOK_SECRET,
            'payments.culqi.webhooks_enabled' => true,
            'payments.culqi.encrypt_payloads' => false,
        ]);

        Notification::fake();
    }

    // ── Cargo confirmado ──────────────────────────────────────────────────

    public function test_approved_charge_pays_the_order_and_credits_exactly_once(): void
    {
        $recharge = $this->recharge();
        $this->fakeApi(charge: fn (Request $r) => Http::response($this->chargeFor($r->data()), 201));

        $order = $this->provider()->createPaymentAttempt($recharge, $this->card());

        $this->assertSame('paid', $order->status);
        $this->assertSame('submitted', $order->submission_status);
        $this->assertSame('culqi', $order->provider);
        $this->assertStringStartsWith('chr_test_', $order->provider_order_id);
        $this->assertSame(1000, $order->amount_minor);
        $this->assertSame('approved', $recharge->fresh()->status);
        $this->assertSame(1, $this->deposits($recharge));
        $this->assertSame(5, $recharge->teacherProfile->fresh()->credits_available);
    }

    public function test_amount_currency_email_and_metadata_are_decided_server_side_and_no_card_data_is_sent(): void
    {
        $recharge = $this->recharge(amountPen: '10.00');
        $this->fakeApi(charge: fn (Request $r) => Http::response($this->chargeFor($r->data()), 201));

        $order = $this->provider()->createPaymentAttempt($recharge, $this->card());

        Http::assertSent(function (Request $r) use ($recharge, $order) {
            if ($r->method() !== 'POST' || ! str_ends_with($r->url(), '/v2/charges')) {
                return false;
            }
            $d = $r->data();

            return $d['amount'] === 1000
                && $d['currency_code'] === 'PEN'
                && $d['email'] === $recharge->teacherProfile->user->email
                && $d['capture'] === true
                && $d['source_id'] === 'tkn_test_abc123XYZ456'
                && $d['metadata']['payment_order_id'] === (string) $order->id
                && $d['metadata']['recharge_request_id'] === (string) $recharge->id
                && $d['metadata']['idempotency_key'] === $order->idempotency_key
                && array_diff(array_keys($d), ['amount', 'currency_code', 'email', 'source_id', 'capture', 'description', 'metadata', 'antifraud_details']) === [];
        });
    }

    public function test_yape_tokens_are_accepted(): void
    {
        $recharge = $this->recharge();
        $this->fakeApi(charge: fn (Request $r) => Http::response($this->chargeFor($r->data()), 201));

        $order = $this->provider()->createPaymentAttempt($recharge, new YapePaymentInstrument('ype_test_abc123XYZ456'));

        $this->assertSame('paid', $order->status);
    }

    // ── Rechazo, 3DS y errores definitivos ────────────────────────────────

    public function test_declined_charge_fails_the_attempt_keeps_the_charge_id_and_never_credits(): void
    {
        $recharge = $this->recharge();
        $this->fakeApi(charge: fn () => Http::response($this->declinedError('chr_test_DECLINED0001'), 400));

        $order = $this->provider()->createPaymentAttempt($recharge, $this->card());

        $this->assertSame('failed', $order->status);
        $this->assertSame('chr_test_DECLINED0001', $order->provider_order_id);
        $this->assertSame('DNGE0015', $order->provider_status_detail);
        $this->assertSame(400, $order->creation_http_status);
        $this->assertSame(0, $this->deposits($recharge));
        $this->assertSame('pending', $recharge->fresh()->status);
    }

    public function test_a_declined_attempt_allows_a_new_attempt_but_never_two_active_ones(): void
    {
        $recharge = $this->recharge();
        $this->fakeApi(charge: fn () => Http::response($this->declinedError('chr_test_DECLINED0002'), 400));
        $first = $this->provider()->createPaymentAttempt($recharge, $this->card());

        $this->fakeApi(charge: fn (Request $r) => Http::response($this->chargeFor($r->data()), 201));
        $second = $this->provider()->createPaymentAttempt($recharge, $this->card('tkn_test_second0000001'));

        $this->assertSame(1, $first->attempt_number);
        $this->assertSame(2, $second->attempt_number);
        $this->assertSame('paid', $second->status);
        $this->assertSame(1, $this->deposits($recharge));

        // Con un intento pagado no se admite otro.
        $this->expectException(RuntimeException::class);
        $this->provider()->createPaymentAttempt($recharge->fresh(), $this->card('tkn_test_third00000001'));
    }

    public function test_3ds_required_response_is_not_a_charge_and_does_not_credit(): void
    {
        $recharge = $this->recharge();
        $this->fakeApi(charge: fn () => Http::response(['user_message' => 'Se requiere autenticación', 'action_code' => 'REVIEW'], 200));

        $order = $this->provider()->createPaymentAttempt($recharge, $this->card());

        $this->assertSame('failed', $order->status);
        $this->assertSame('requires_3ds', $order->provider_status_detail);
        $this->assertSame(0, $this->deposits($recharge));
    }

    public function test_authentication_errors_send_the_order_to_review_without_retrying(): void
    {
        $recharge = $this->recharge();
        $this->fakeApi(charge: fn () => Http::response(['object' => 'error', 'type' => 'authentication_error', 'code' => 'invalid_key'], 401));

        $order = $this->provider()->createPaymentAttempt($recharge, $this->card());

        $this->assertSame('failed', $order->status);
        $this->assertNotNull($order->fresh()->review_reason);
        $this->assertSame(0, $this->deposits($recharge));
        $this->assertSame(1, $this->postCount());
    }

    public function test_tokens_of_another_environment_or_with_a_bad_shape_are_refused_before_any_http_call(): void
    {
        $recharge = $this->recharge();
        Http::fake();

        foreach (['tkn_live_abc123XYZ456', 'not-a-token', ''] as $token) {
            try {
                $this->provider()->createPaymentAttempt($recharge, new CardPaymentInstrument($token, 'visa'));
                $this->fail("Debió rechazar el token [{$token}].");
            } catch (RuntimeException) {
                // esperado
            }
        }

        Http::assertNothingSent();
        $this->assertSame(0, PaymentOrder::count());
    }

    // ── Errores de red / resultado incierto ───────────────────────────────

    public function test_a_network_error_leaves_the_attempt_uncertain_and_the_charge_is_never_retried(): void
    {
        $recharge = $this->recharge();
        $this->fakeApi(
            charge: fn () => throw new ConnectionException('cURL error 28: timeout'),
            list: fn () => Http::response(['data' => []], 200)
        );

        try {
            $this->provider()->createPaymentAttempt($recharge, $this->card());
            $this->fail('Debió lanzar RuntimeException por resultado incierto.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('incierto', $e->getMessage());
        }

        $order = PaymentOrder::firstOrFail();
        $this->assertSame('uncertain', $order->submission_status);
        $this->assertSame('pending', $order->status);
        $this->assertSame(1, $this->postCount(), 'La escritura no debe reintentarse.');

        // Un segundo intento del usuario NO envía otro cobro: reconcilia primero.
        try {
            $this->provider()->createPaymentAttempt($recharge, $this->card('tkn_test_retry000001'));
        } catch (RuntimeException) {
            // esperado: sigue incierto
        }
        $this->assertSame(1, $this->postCount(), 'Un reintento del usuario no puede duplicar el cargo.');
        $this->assertSame(0, $this->deposits($recharge));
        $this->assertSame(1, PaymentOrder::count());
    }

    public function test_a_server_error_is_uncertain_not_failed(): void
    {
        $recharge = $this->recharge();
        $this->fakeApi(charge: fn () => Http::response(['object' => 'error', 'type' => 'api_error'], 503));

        try {
            $this->provider()->createPaymentAttempt($recharge, $this->card());
        } catch (RuntimeException) {
            // esperado
        }

        $order = PaymentOrder::firstOrFail();
        $this->assertSame('uncertain', $order->submission_status);
        $this->assertSame('pending', $order->status);
    }

    public function test_an_uncertain_attempt_is_recovered_when_the_charge_exists_in_culqi(): void
    {
        $recharge = $this->recharge();
        $created = null;
        $this->fakeApi(
            charge: function (Request $r) use (&$created) {
                $created = $this->chargeFor($r->data());

                return throw new ConnectionException('timeout después de que Culqi procesó el cargo');
            },
            list: function () use (&$created) {
                return Http::response(['data' => [$created]], 200);
            }
        );

        try {
            $this->provider()->createPaymentAttempt($recharge, $this->card());
        } catch (RuntimeException) {
            // esperado
        }

        $outcome = app(CulqiPaymentReconciliationService::class)->reconcileUncertainSubmission(PaymentOrder::firstOrFail());

        $this->assertSame('paid', $outcome);
        $this->assertSame(1, $this->deposits($recharge));
        $this->assertSame($created['id'], PaymentOrder::firstOrFail()->provider_order_id);
        $this->assertSame(1, $this->postCount());
    }

    public function test_an_uncertain_attempt_without_a_findable_charge_ends_in_review_never_in_failed(): void
    {
        config(['payments.culqi.recovery.uncertain_search_max_attempts' => 2]);
        $recharge = $this->recharge();
        $this->fakeApi(
            charge: fn () => throw new ConnectionException('timeout'),
            list: fn () => Http::response(['data' => []], 200)
        );
        try {
            $this->provider()->createPaymentAttempt($recharge, $this->card());
        } catch (RuntimeException) {
        }

        $reconciler = app(CulqiPaymentReconciliationService::class);
        $first = $reconciler->reconcileUncertainSubmission(PaymentOrder::firstOrFail());
        $second = $reconciler->reconcileUncertainSubmission(PaymentOrder::firstOrFail());

        $this->assertSame('uncertain', $first);
        $this->assertSame('review', $second);
        $order = PaymentOrder::firstOrFail();
        $this->assertSame('pending', $order->status, 'Un cargo real podría aparecer tarde: no se cierra como fallido.');
        $this->assertNotNull($order->review_reason);
        $this->assertSame(0, $this->deposits($recharge));
    }

    public function test_a_reentrant_attempt_while_the_first_is_in_flight_does_not_send_a_second_charge(): void
    {
        $recharge = $this->recharge();
        $nested = null;
        $this->fakeApi(
            charge: function (Request $r) use ($recharge, &$nested) {
                // Mientras "vuela" el primer POST, llega un segundo intento concurrente.
                try {
                    $this->provider()->createPaymentAttempt($recharge, $this->card('tkn_test_concurrent0001'));
                } catch (RuntimeException $e) {
                    $nested = $e;
                }

                return Http::response($this->chargeFor($r->data()), 201);
            },
            list: fn () => Http::response(['data' => []], 200)
        );

        $order = $this->provider()->createPaymentAttempt($recharge, $this->card());

        $this->assertInstanceOf(RuntimeException::class, $nested);
        $this->assertSame(1, $this->postCount());
        $this->assertSame('paid', $order->status);
        $this->assertSame(1, $this->deposits($recharge));
    }

    // ── Reconciliación: contexto y fail-closed ────────────────────────────

    public function test_a_charge_with_a_different_amount_is_sent_to_review_and_not_credited(): void
    {
        $recharge = $this->recharge();
        $this->fakeApi(charge: fn (Request $r) => Http::response($this->chargeFor($r->data(), ['amount' => 100, 'current_amount' => 100]), 201));

        $order = $this->provider()->createPaymentAttempt($recharge, $this->card());

        $this->assertSame(0, $this->deposits($recharge));
        $this->assertNotNull($order->fresh()->review_reason);
        $this->assertNotSame('paid', $order->fresh()->status);
    }

    public function test_a_charge_whose_metadata_belongs_to_another_attempt_is_not_credited(): void
    {
        $recharge = $this->recharge();
        $order = $this->orderWithChargeId($recharge, 'chr_test_FOREIGNMETA001');

        $charge = $this->chargeFor(['amount' => 1000, 'currency_code' => 'PEN', 'email' => 'x@example.com', 'metadata' => ['payment_order_id' => '999', 'recharge_request_id' => '999', 'idempotency_key' => 'otro']], ['id' => 'chr_test_FOREIGNMETA001']);
        $result = app(CulqiPaymentReconciliationService::class)->applyTruth($order, null, $charge);

        $this->assertSame('review', $result);
        $this->assertSame(0, $this->deposits($recharge));
    }

    public function test_a_live_charge_is_never_credited_while_configured_in_test_mode(): void
    {
        $recharge = $this->recharge();
        $order = $this->orderWithChargeId($recharge, 'chr_live_WRONGENV0000001');
        $charge = $this->chargeFor($this->payloadFor($order), ['id' => 'chr_live_WRONGENV0000001']);

        $this->assertSame('review', app(CulqiPaymentReconciliationService::class)->applyTruth($order, null, $charge));
        $this->assertSame(0, $this->deposits($recharge));
    }

    public function test_unknown_or_ambiguous_outcomes_fail_closed(): void
    {
        $cases = [
            'paid=true sin resultado reconocido' => ['paid' => true, 'outcome' => ['type' => 'otro', 'code' => 'XYZ']],
            'sin capturar' => ['capture' => false],
            'disputa' => ['dispute' => true],
            'sin outcome' => ['outcome' => null],
        ];

        foreach ($cases as $label => $override) {
            $this->assertSame('review', CulqiChargeMapper::normalize($this->chargeFor(['amount' => 1000, 'currency_code' => 'PEN', 'email' => 'a@b.pe', 'metadata' => []], $override)), $label);
        }
        $this->assertSame('paid', CulqiChargeMapper::normalize($this->chargeFor(['amount' => 1000, 'currency_code' => 'PEN', 'email' => 'a@b.pe', 'metadata' => []])));
    }

    public function test_replaying_the_same_truth_many_times_credits_once(): void
    {
        $recharge = $this->recharge();
        $this->fakeApi(charge: fn (Request $r) => Http::response($this->chargeFor($r->data()), 201));
        $order = $this->provider()->createPaymentAttempt($recharge, $this->card());
        $charge = $this->chargeFor($this->payloadFor($order), ['id' => $order->provider_order_id]);
        $reconciler = app(CulqiPaymentReconciliationService::class);

        foreach (range(1, 5) as $_) {
            $this->assertSame('paid', $reconciler->applyTruth($order->fresh(), null, $charge));
        }

        $this->assertSame(1, $this->deposits($recharge));
        $this->assertSame(5, $recharge->teacherProfile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "recharge:{$recharge->id}:deposit")->count());
    }

    // ── Devoluciones ──────────────────────────────────────────────────────

    public function test_full_refund_reverses_once_and_partial_refund_goes_to_review(): void
    {
        $recharge = $this->recharge();
        $this->fakeApi(charge: fn (Request $r) => Http::response($this->chargeFor($r->data()), 201));
        $order = $this->provider()->createPaymentAttempt($recharge, $this->card());
        $base = $this->chargeFor($this->payloadFor($order), ['id' => $order->provider_order_id]);
        $reconciler = app(CulqiPaymentReconciliationService::class);

        $partial = $reconciler->applyTruth($order->fresh(), null, array_replace($base, ['amount_refunded' => 400, 'current_amount' => 600]));
        $this->assertSame('review', $partial);
        $this->assertSame('approved', $recharge->fresh()->status, 'Una devolución parcial no revierte nada sola.');

        $order->fresh()->update(['review_reason' => null]);
        $full = array_replace($base, ['amount_refunded' => 1000, 'current_amount' => 0]);
        $this->assertSame('reversed', $reconciler->applyTruth($order->fresh(), null, $full));
        $this->assertSame('reversed', $recharge->fresh()->status);
        $this->assertSame(0, $recharge->teacherProfile->fresh()->credits_available);

        // Repetir la devolución es idempotente (no revierte ni alerta dos veces).
        $this->assertSame('reversed', $reconciler->applyTruth($order->fresh(), null, $full));
        $this->assertSame(1, CreditTransaction::where('recharge_request_id', $recharge->id)->where('type', '!=', 'deposit')->count());
    }

    // ── Webhooks ──────────────────────────────────────────────────────────

    public function test_webhook_is_a_404_while_disabled_and_a_401_with_the_wrong_secret(): void
    {
        config(['payments.culqi.webhooks_enabled' => false]);
        $this->postJson('/api/webhooks/culqi/'.self::WEBHOOK_SECRET, $this->event('evt_test_1'))->assertNotFound();

        config(['payments.culqi.webhooks_enabled' => true]);
        $this->postJson('/api/webhooks/culqi/not-the-secret', $this->event('evt_test_1'))->assertUnauthorized();
        $this->assertSame(0, PaymentWebhook::count());
    }

    public function test_webhook_without_a_configured_secret_rejects_everything(): void
    {
        config(['payments.culqi.webhook_secret' => null]);

        $this->postJson('/api/webhooks/culqi/anything', $this->event('evt_test_1'))->assertUnauthorized();
        $this->postJson('/api/webhooks/culqi/', $this->event('evt_test_1'))->assertNotFound();
    }

    public function test_malformed_webhook_with_the_right_secret_is_a_400(): void
    {
        $this->call('POST', '/api/webhooks/culqi/'.self::WEBHOOK_SECRET, [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"nope":true}')->assertStatus(400);
        $this->call('POST', '/api/webhooks/culqi/'.self::WEBHOOK_SECRET, [], [], [], ['CONTENT_TYPE' => 'application/json'], 'no es json')->assertStatus(400);
    }

    public function test_duplicate_webhook_events_are_persisted_and_dispatched_once(): void
    {
        Bus::fake();

        foreach (range(1, 3) as $_) {
            $this->postJson('/api/webhooks/culqi/'.self::WEBHOOK_SECRET, $this->event('evt_test_dup', 'charge.creation.succeeded', 'chr_test_DUP0000000001'))->assertOk();
        }

        $this->assertSame(1, PaymentWebhook::where('provider', 'culqi')->where('event_id', 'evt_test_dup')->count());
        Bus::assertDispatchedTimes(ProcessCulqiWebhook::class, 1);
    }

    public function test_a_webhook_claiming_paid_does_not_credit_when_the_api_says_declined(): void
    {
        $recharge = $this->recharge();
        $order = $this->orderWithChargeId($recharge, 'chr_test_LIAR0000000001');
        $declined = $this->chargeFor($this->payloadFor($order), [
            'id' => 'chr_test_LIAR0000000001',
            'outcome' => ['type' => 'operacion_denegada', 'code' => 'DNGE0015', 'decline_code' => 'insufficient_funds'],
        ]);
        $this->fakeApi(get: fn () => Http::response($declined, 200));

        $this->processWebhook($this->event('evt_test_liar', 'charge.creation.succeeded', 'chr_test_LIAR0000000001'));

        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame(0, $this->deposits($recharge));
    }

    public function test_two_different_events_for_the_same_paid_charge_credit_once(): void
    {
        $recharge = $this->recharge();
        $order = $this->orderWithChargeId($recharge, 'chr_test_PAID0000000001');
        $paid = $this->chargeFor($this->payloadFor($order), ['id' => 'chr_test_PAID0000000001']);
        $this->fakeApi(get: fn () => Http::response($paid, 200));

        $this->processWebhook($this->event('evt_test_a', 'charge.creation.succeeded', 'chr_test_PAID0000000001'));
        $this->processWebhook($this->event('evt_test_b', 'charge.update.succeeded', 'chr_test_PAID0000000001'));
        $this->processWebhook($this->event('evt_test_c', 'charge.capture.succeeded', 'chr_test_PAID0000000001'));

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, $this->deposits($recharge));
        $this->assertSame(3, PaymentWebhook::where('provider', 'culqi')->where('status', 'processed')->count());
    }

    public function test_unmanaged_event_types_are_closed_without_calling_the_api(): void
    {
        Http::fake();

        $this->processWebhook($this->event('evt_test_customer', 'customer.creation.succeeded', 'cus_test_ABC'));

        Http::assertNothingSent();
        $this->assertSame('processed', PaymentWebhook::where('event_id', 'evt_test_customer')->firstOrFail()->status);
    }

    public function test_a_refund_event_reconciles_against_the_api_and_reverses(): void
    {
        $recharge = $this->recharge();
        $this->fakeApi(charge: fn (Request $r) => Http::response($this->chargeFor($r->data()), 201));
        $order = $this->provider()->createPaymentAttempt($recharge, $this->card());
        $refunded = $this->chargeFor($this->payloadFor($order), ['id' => $order->provider_order_id, 'amount_refunded' => 1000, 'current_amount' => 0]);
        $this->fakeApi(get: fn () => Http::response($refunded, 200));

        $webhook = $this->persistEvent($this->event('evt_test_refund', 'refund.creation.succeeded', null, ['charge_id' => $order->provider_order_id, 'id' => 'ref_test_000000000001']));
        (new ProcessCulqiWebhook($webhook->id))->handle(app(CulqiPaymentReconciliationService::class));

        $this->assertSame('reversed', $recharge->fresh()->status);
    }

    public function test_the_job_retries_when_the_order_is_not_there_yet_instead_of_dropping_the_event(): void
    {
        $webhook = $this->persistEvent($this->event('evt_test_early', 'charge.creation.succeeded', 'chr_test_EARLY000000001'));

        $this->expectException(RuntimeException::class);
        (new ProcessCulqiWebhook($webhook->id))->handle(app(CulqiPaymentReconciliationService::class));
    }

    // ── Integridad: recargas gestionadas por el proveedor ─────────────────

    public function test_a_culqi_recharge_cannot_be_approved_or_credited_without_a_paid_order(): void
    {
        $recharge = $this->recharge();
        $service = app(\App\Services\RechargeApprovalService::class);

        try {
            $service->credit($recharge, null);
            $this->fail('Debió exigir un pago confirmado por Culqi.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertStringContainsString('Culqi', $e->getMessage());
        }

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        try {
            $service->credit($recharge, $admin->id);
            $this->fail('Un admin no puede aprobar a mano una recarga de Culqi.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertStringContainsString('gestionada automáticamente por Culqi', $e->getMessage());
        }

        $this->assertFalse($admin->can('approve', $recharge));
        $this->assertFalse($admin->can('reject', $recharge));
        $this->assertFalse($admin->can('reverse', $recharge));
        $this->assertSame(0, $this->deposits($recharge));
    }

    public function test_mercado_pago_manual_guards_keep_their_exact_wording(): void
    {
        $recharge = $this->recharge(method: 'mercadopago');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        try {
            app(\App\Services\RechargeApprovalService::class)->credit($recharge, $admin->id);
            $this->fail('Debió abortar.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame('Esta recarga es gestionada automáticamente por Mercado Pago y no puede aprobarse manualmente. Espera la confirmación del proveedor.', $e->getMessage());
        }
    }

    // ── Configuración / cliente ───────────────────────────────────────────

    public function test_config_rejects_placeholders_mixed_modes_and_live_keys_outside_production(): void
    {
        config(['payments.culqi.private_key' => 'tu_llave_privada']);
        $this->assertNotEmpty(CulqiConfig::problems());

        config(['payments.culqi.private_key' => 'sk_live_AbCdEf0123456789']);
        $this->assertStringContainsString('mezclan modos', implode(' ', CulqiConfig::problems()));

        config(['payments.culqi.public_key' => 'pk_live_AbCdEf0123456789', 'payments.culqi.env' => 'live']);
        $this->assertStringContainsString('solo van a producción', implode(' ', CulqiConfig::problems()));

        config(['payments.culqi.private_key' => 'sk_test_AbCdEf0123456789', 'payments.culqi.public_key' => 'pk_test_AbCdEf0123456789', 'payments.culqi.env' => 'test']);
        $this->assertSame([], CulqiConfig::problems());
    }

    public function test_the_container_boots_culqi_only_with_complete_and_coherent_configuration(): void
    {
        $this->assertInstanceOf(CulqiPaymentProvider::class, app(PaymentProviderContract::class));

        config(['payments.culqi.private_key' => null]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Culqi incompleta/');
        app(PaymentProviderContract::class);
    }

    public function test_the_http_client_never_retries_writes_but_retries_connection_failures_on_reads(): void
    {
        $calls = ['POST' => 0, 'GET' => 0];
        Http::fake(function (Request $r) use (&$calls) {
            $calls[$r->method()]++;

            throw new ConnectionException('boom');
        });
        $client = CulqiClient::fromConfig();

        $write = $client->createCharge(['amount' => 1000]);
        $this->assertTrue($write->uncertain());
        $this->assertSame(1, $calls['POST'], 'La escritura no se reintenta.');

        $read = $client->getCharge('chr_test_X');
        $this->assertTrue($read->uncertain());
        $this->assertSame(2, $calls['GET'], 'La lectura sí se reintenta una vez ante fallos de conexión.');
    }

    public function test_results_and_logs_never_carry_the_secret_key(): void
    {
        Http::fake(['*' => Http::response(['object' => 'error', 'type' => 'authentication_error'], 401)]);
        $result = CulqiClient::fromConfig()->getCharge('chr_test_X');

        $this->assertStringNotContainsString('sk_test_AbCdEf0123456789', json_encode($result));
        $this->assertStringNotContainsString('sk_test_AbCdEf0123456789', serialize($result));
    }

    public function test_payload_encryptor_produces_the_aes_rsa_envelope_and_never_leaks_the_plaintext(): void
    {
        $ns = \App\Payment\Culqi\CulqiPayloadEncryptor::phpseclibNamespace();
        if ($ns === null) {
            $this->markTestSkipped('phpseclib no está instalado.');
        }
        $rsaClass = $ns.'\\Crypt\\RSA';

        $private = $rsaClass::createKey(2048);
        $public = $private->getPublicKey();
        config([
            'payments.culqi.encrypt_payloads' => true,
            'payments.culqi.rsa_id' => '2ab335ad-c40d-4375-8dad-3ea315de23b0',
            'payments.culqi.rsa_public_key' => str_replace("\n", '\n', (string) $public),
        ]);
        Http::fake(fn (Request $r) => Http::response($this->chargeFor(['amount' => 1000, 'currency_code' => 'PEN', 'email' => 'a@b.pe', 'metadata' => []]), 201));

        CulqiClient::fromConfig()->createCharge(['amount' => 1000, 'currency_code' => 'PEN', 'email' => 'secreto@example.com', 'source_id' => 'tkn_test_abc']);

        Http::assertSent(function (Request $r) use ($private, $rsaClass) {
            $d = $r->data();
            if (array_keys($d) !== ['encrypted_data', 'encrypted_key', 'encrypted_iv']) {
                return false;
            }
            $rsa = $private->withPadding($rsaClass::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256');
            $key = $rsa->decrypt(base64_decode($d['encrypted_key']));
            $iv = $rsa->decrypt(base64_decode($d['encrypted_iv']));

            return $r->hasHeader('x-culqi-rsa-id', '2ab335ad-c40d-4375-8dad-3ea315de23b0')
                && strlen($key) === 32 && strlen($iv) === 16
                && ! str_contains(json_encode($d), 'secreto@example.com');
        });
    }

    // ── Revisión: intentos en vuelo, cifrado inválido, barrido y sonda ────

    public function test_invalid_encryption_config_aborts_before_sending_and_leaves_the_attempt_retryable(): void
    {
        $recharge = $this->recharge();
        config(['payments.culqi.encrypt_payloads' => true, 'payments.culqi.rsa_id' => '2ab335ad-c40d-4375-8dad-3ea315de23b0', 'payments.culqi.rsa_public_key' => 'esto-no-es-un-pem']);
        $this->fakeApi(charge: fn (Request $r) => Http::response($this->chargeFor($r->data()), 201));

        try {
            $this->provider()->createPaymentAttempt($recharge, $this->card());
            $this->fail('Debió fallar: el payload no puede cifrarse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No se cobró nada', $e->getMessage());
        }

        $this->assertSame(0, $this->postCount(), 'Nada salió hacia Culqi.');
        $this->assertSame('prepared', PaymentOrder::firstOrFail()->submission_status, 'No queda atascado en submitting.');

        // Corregida la configuración, el MISMO intento se envía y cobra una vez.
        config(['payments.culqi.encrypt_payloads' => false]);
        $order = $this->provider()->createPaymentAttempt($recharge, $this->card('tkn_test_fixed0000001'));
        $this->assertSame('paid', $order->status);
        $this->assertSame(1, PaymentOrder::count());
        $this->assertSame(1, $this->deposits($recharge));
    }

    public function test_a_recent_submitting_attempt_is_in_flight_and_does_not_burn_search_attempts(): void
    {
        $recharge = $this->recharge();
        $order = $this->orderWithChargeId($recharge, 'chr_test_UNUSED000000001');
        $order->update(['provider_order_id' => null, 'submission_status' => 'submitting']);
        $this->fakeApi(list: fn () => Http::response(['data' => []], 200));
        $reconciler = app(CulqiPaymentReconciliationService::class);

        foreach (range(1, 10) as $_) {
            $this->assertSame('in_flight', $reconciler->reconcileUncertainSubmission($order->fresh()));
        }
        $this->assertSame(0, $order->fresh()->recovery_attempts);
        $this->assertNull($order->fresh()->review_reason);

        // Pasada la gracia (proceso caído a mitad), sí se busca y se contabiliza.
        PaymentOrder::whereKey($order->id)->update(['updated_at' => now()->subMinutes(10)]);
        $this->assertSame('uncertain', $reconciler->reconcileUncertainSubmission($order->fresh()));
        $this->assertSame(1, $order->fresh()->recovery_attempts);
    }

    public function test_reconcile_command_sweeps_stuck_orders_but_skips_reviewed_and_never_sent_ones(): void
    {
        $stuck = $this->orderWithChargeId($this->recharge(), 'chr_test_STUCK00000000001');
        $reviewed = $this->orderWithChargeId($this->recharge(), 'chr_test_REVIEW0000000001');
        $reviewed->update(['review_reason' => 'revisión humana pendiente']);
        $prepared = $this->orderWithChargeId($this->recharge(), 'chr_test_PREPARED000000001');
        $prepared->update(['provider_order_id' => null, 'submission_status' => 'prepared']);

        $this->fakeApi(get: function (Request $r) use ($stuck, $reviewed) {
            $id = basename(parse_url($r->url(), PHP_URL_PATH));
            $order = $id === 'chr_test_STUCK00000000001' ? $stuck : $reviewed;

            return Http::response($this->chargeFor($this->payloadFor($order), ['id' => $id]), 200);
        });

        $this->artisan('culqi:reconcile', ['--minutes' => 0])->assertExitCode(0);

        $this->assertSame('paid', $stuck->fresh()->status);
        $this->assertSame('pending', $reviewed->fresh()->status, 'Una orden en revisión humana no se reprocesa en el barrido.');
        $this->assertSame('prepared', $prepared->fresh()->submission_status);

        // La re-comprobación puntual SÍ puede tomar una orden en revisión.
        $this->artisan('culqi:reconcile', ['--order' => $reviewed->id])->assertExitCode(0);
        $this->assertSame('paid', $reviewed->fresh()->status);
    }

    public function test_sandbox_smoke_requires_explicit_confirmation_and_a_qa_environment(): void
    {
        Http::fake();

        $this->artisan('culqi:sandbox-smoke')->assertExitCode(1);

        $this->app['env'] = 'production';
        $this->artisan('culqi:sandbox-smoke', ['--confirm-sandbox' => true])->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertSame(0, User::where('email', 'like', 'sandbox-smoke%')->count());
    }

    // ── Revisión de seguridad ─────────────────────────────────────────────

    public function test_only_pending_culqi_recharges_can_be_charged(): void
    {
        Http::fake();

        foreach ([['mercadopago', 'pending'], ['culqi', 'rejected'], ['culqi', 'approved'], ['yape', 'pending']] as [$method, $status]) {
            $recharge = $this->recharge(method: $method);
            $recharge->update(['status' => $status]);
            try {
                $this->provider()->createPaymentAttempt($recharge, $this->card());
                $this->fail("Debió rechazar el cobro de [{$method}/{$status}].");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('no admite un cobro', $e->getMessage());
            }
        }

        Http::assertNothingSent();
        $this->assertSame(0, PaymentOrder::count());
    }

    public function test_a_confirmed_charge_on_a_no_longer_creditable_recharge_goes_to_review_without_exceptions_or_credit(): void
    {
        $recharge = $this->recharge();
        $order = $this->orderWithChargeId($recharge, 'chr_test_LATEREJECT00001');
        $recharge->update(['status' => 'rejected']);
        $paid = $this->chargeFor($this->payloadFor($order), ['id' => 'chr_test_LATEREJECT00001']);

        $result = app(CulqiPaymentReconciliationService::class)->applyTruth($order, null, $paid);

        $this->assertSame('review', $result);
        $this->assertStringContainsString('requiere decisión humana', $order->fresh()->review_reason);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, $this->deposits($recharge));
    }

    public function test_a_short_webhook_secret_never_authenticates_and_is_a_configuration_problem(): void
    {
        config(['payments.culqi.webhook_secret' => 'corto-pero-coincide']);

        $this->postJson('/api/webhooks/culqi/corto-pero-coincide', $this->event('evt_test_short'))->assertUnauthorized();
        $this->assertStringContainsString('al menos 32 caracteres', implode(' ', CulqiConfig::problems()));
        $this->assertSame(0, PaymentWebhook::count());
    }

    public function test_official_hosts_are_enforced_outside_local_and_testing(): void
    {
        config(['payments.culqi.base_url' => 'https://api.culqi.com.evil.example', 'payments.culqi.secure_url' => 'http://secure.culqi.com']);
        $this->assertSame([], array_filter(CulqiConfig::problems(), fn ($p) => str_contains($p, 'BASE_URL') || str_contains($p, 'SECURE_URL')), 'En testing se permiten dobles de prueba.');

        $this->app['env'] = 'production';
        $problems = implode(' ', CulqiConfig::problems());
        $this->assertStringContainsString('CULQI_BASE_URL', $problems);
        $this->assertStringContainsString('CULQI_SECURE_URL', $problems);
    }

    public function test_stored_webhook_payload_is_minimal_and_carries_no_payer_data(): void
    {
        $payload = $this->event('evt_test_pii', 'charge.creation.succeeded', null, ['id' => 'chr_test_PII000000000001', 'object' => 'charge', 'email' => 'pagador@example.com', 'source' => ['card_number' => '411111******1111'], 'antifraud_details' => ['phone' => '999999999']]);
        $webhook = $this->persistEvent($payload);

        $stored = json_encode($webhook->fresh()->payload);
        $this->assertStringContainsString('chr_test_PII000000000001', $stored);
        foreach (['pagador@example.com', '411111', '999999999', 'antifraud'] as $leak) {
            $this->assertStringNotContainsString($leak, $stored);
        }
    }

    public function test_on_the_last_attempt_an_event_for_a_charge_unknown_to_mova_is_closed_without_a_critical_alert(): void
    {
        $webhook = $this->persistEvent($this->event('evt_test_foreign', 'charge.creation.succeeded', 'chr_test_FOREIGN00000001'));
        $job = new ProcessCulqiWebhook($webhook->id);
        $queueJob = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $queueJob->shouldReceive('attempts')->andReturn($job->tries);
        $job->setJob($queueJob);

        $job->handle(app(CulqiPaymentReconciliationService::class));

        $webhook = $webhook->fresh();
        $this->assertSame('processed', $webhook->status);
        $this->assertStringContainsString('ajeno a MOVA', $webhook->error);
        $this->assertSame(0, \App\Models\OperationalAlert::count());
    }

    public function test_the_sweep_catches_a_refund_whose_webhook_never_arrived(): void
    {
        $recharge = $this->recharge();
        $this->fakeApi(charge: fn (Request $r) => Http::response($this->chargeFor($r->data()), 201));
        $order = $this->provider()->createPaymentAttempt($recharge, $this->card());
        $this->assertSame('approved', $recharge->fresh()->status);

        $refunded = $this->chargeFor($this->payloadFor($order), ['id' => $order->provider_order_id, 'amount_refunded' => 1000, 'current_amount' => 0]);
        $this->fakeApi(get: fn () => Http::response($refunded, 200));
        PaymentOrder::whereKey($order->id)->update(['last_verified_at' => now()->subHours(3)]);

        $this->artisan('culqi:reconcile')->assertExitCode(0);

        $this->assertSame('reversed', $recharge->fresh()->status);
        $this->assertSame(0, $recharge->teacherProfile->fresh()->credits_available);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function provider(): CulqiPaymentProvider
    {
        return app(CulqiPaymentProvider::class);
    }

    private function card(string $token = 'tkn_test_abc123XYZ456'): CardPaymentInstrument
    {
        return new CardPaymentInstrument($token, 'visa');
    }

    private function recharge(string $amountPen = '10.00', int $credits = 5, string $method = 'culqi'): RechargeRequest
    {
        $user = User::factory()->create();
        $user->assignRole('teacher');
        $profile = TeacherProfile::create(['user_id' => $user->id, 'is_verified' => true, 'credits_available' => 0, 'credits_reserved' => 0]);

        return RechargeRequest::create([
            'teacher_profile_id' => $profile->id,
            'package_code' => 'inicio',
            'package_name' => 'Inicio',
            'credits' => $credits,
            'amount_pen' => $amountPen,
            'payment_method' => $method,
            'operation_number' => null,
            'operation_number_normalized' => null,
            'status' => 'pending',
        ]);
    }

    private function deposits(RechargeRequest $recharge): int
    {
        return CreditTransaction::where('recharge_request_id', $recharge->id)->where('type', 'deposit')->count();
    }

    /** POST /v2/charges realmente emitidos (incluye los que terminan en ConnectionException). */
    private function postCount(): int
    {
        return $this->chargePosts;
    }

    /** Forma REAL de una venta exitosa en el sandbox (paid=false, outcome.type=venta_exitosa). */
    private function chargeFor(array $payload, array $override = []): array
    {
        return array_replace([
            'object' => 'charge',
            'id' => 'chr_test_'.Str::random(16),
            'creation_date' => now()->getTimestampMs(),
            'amount' => $payload['amount'] ?? 0,
            'amount_refunded' => 0,
            'current_amount' => $payload['amount'] ?? 0,
            'installments' => 0,
            'currency_code' => $payload['currency_code'] ?? 'PEN',
            'email' => $payload['email'] ?? 'a@b.pe',
            'capture' => true,
            'paid' => false,
            'dispute' => false,
            'duplicated' => false,
            'outcome' => ['type' => 'venta_exitosa', 'code' => 'AUT0000', 'merchant_message' => 'La operación de venta ha sido autorizada exitosamente', 'user_message' => 'Su compra ha sido exitosa'],
            'metadata' => $payload['metadata'] ?? [],
        ], $override);
    }

    /** Forma REAL de un rechazo en el sandbox: HTTP 400 con objeto error. */
    private function declinedError(string $chargeId): array
    {
        return [
            'object' => 'error',
            'type' => 'operacion_denegada',
            'charge_id' => $chargeId,
            'code' => 'DNGE0015',
            'decline_code' => 'insufficient_funds',
            'merchant_message' => 'Fondos insuficientes.',
            'user_message' => 'Su tarjeta no tiene fondos suficientes.',
        ];
    }

    private function payloadFor(PaymentOrder $order): array
    {
        return [
            'amount' => $order->amount_minor,
            'currency_code' => $order->currency,
            'email' => 'payer@example.com',
            'metadata' => [
                'payment_order_id' => (string) $order->id,
                'recharge_request_id' => (string) $order->recharge_request_id,
                'attempt' => (string) $order->attempt_number,
                'idempotency_key' => (string) $order->idempotency_key,
            ],
        ];
    }

    private function orderWithChargeId(RechargeRequest $recharge, string $chargeId): PaymentOrder
    {
        return PaymentOrder::create([
            'recharge_request_id' => $recharge->id,
            'attempt_number' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'provider' => 'culqi',
            'provider_order_id' => $chargeId,
            'submission_status' => 'submitted',
            'status' => 'pending',
            'amount_minor' => 1000,
            'currency' => 'PEN',
        ]);
    }

    /** Router de Http::fake por método/ruta; cualquier llamada no prevista es un 500 visible. */
    private function fakeApi(?callable $charge = null, ?callable $list = null, ?callable $get = null): void
    {
        // Http::fake() acumula stubs y gana el primero: se parte de una fábrica limpia en cada llamada.
        Http::swap(new HttpFactory());

        Http::fake(function (Request $r) use ($charge, $list, $get) {
            $path = parse_url($r->url(), PHP_URL_PATH);

            if ($r->method() === 'POST' && $path === '/v2/charges') {
                $this->chargePosts++;
            }

            if ($r->method() === 'POST' && $path === '/v2/charges' && $charge) {
                return $charge($r);
            }
            if ($r->method() === 'GET' && $path === '/v2/charges' && $list) {
                return $list($r);
            }
            if ($r->method() === 'GET' && str_starts_with((string) $path, '/v2/charges/') && $get) {
                return $get($r);
            }

            return Http::response(['object' => 'error', 'type' => 'api_error', 'merchant_message' => 'llamada no prevista en el test'], 500);
        });
    }

    private function event(string $id, string $type = 'charge.creation.succeeded', ?string $objectId = 'chr_test_X', array $data = []): array
    {
        return [
            'object' => 'event',
            'id' => $id,
            'type' => $type,
            'creation_date' => time(),
            // `data` llega como string JSON (esquema NotifyEvent de Culqi).
            'data' => json_encode($data ?: ['id' => $objectId, 'object' => explode('.', $type)[0]]),
        ];
    }

    private function persistEvent(array $payload): PaymentWebhook
    {
        $event = $this->provider()->verifyWebhook(json_encode($payload), ['x-mova-webhook-token' => self::WEBHOOK_SECRET]);
        $this->assertNotNull($event);

        return app(\App\Services\PaymentWebhookService::class)->persist('culqi', $event);
    }

    private function processWebhook(array $payload): void
    {
        $webhook = $this->persistEvent($payload);
        (new ProcessCulqiWebhook($webhook->id))->handle(app(CulqiPaymentReconciliationService::class));
    }
}
