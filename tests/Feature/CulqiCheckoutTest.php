<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\LegalAcceptance;
use App\Models\PaymentOrder;
use App\Models\PaymentWebhook;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\RechargeApprovalService;
use App\Support\CheckoutProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\Concerns\AssertsFinancialInvariants;
use Tests\Concerns\FakesCulqi;
use Tests\TestCase;

/**
 * Recorrido completo del checkout de Culqi sobre HTTP real de Laravel:
 * usuario → store/show → pay (token) → [3DS] → Culqi (doble) → conciliación → ledger → créditos.
 * El frontend nunca es fuente de verdad: monto, créditos y estado salen siempre del servidor.
 */
class CulqiCheckoutTest extends TestCase
{
    use AssertsFinancialInvariants;
    use FakesCulqi;
    use RefreshDatabase;

    private const SECRET = 'whsec_0123456789abcdef0123456789abcdef01234567';
    private const DEVICE = '8019959c-fab1-49eb-bbbe-b846d308d8df';
    private const TOKEN = 'tkn_test_AbCdEf0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('teacher', 'web');
        Role::findOrCreate('admin', 'web');
        Notification::fake();

        config([
            'payments.enabled' => true,
            'payments.provider' => 'culqi',
            'payments.culqi.env' => 'test',
            'payments.culqi.public_key' => 'pk_test_AbCdEf0123456789',
            'payments.culqi.private_key' => 'sk_test_ZyXwVu9876543210',
            'payments.culqi.webhook_secret' => self::SECRET,
            'payments.culqi.webhooks_enabled' => true,
            'payments.culqi.methods' => 'card,yape,order',
            'payments.culqi.checkout_allowlist' => '',
        ]);
    }

    // ── Entrada: selección de paquete y pantalla ──────────────────────────

    public function test_store_creates_a_culqi_recharge_from_the_server_catalogue_and_redirects_to_the_checkout(): void
    {
        [$user, $profile] = $this->teacher();

        $response = $this->actingAs($user)->post(route('teacher.credits.checkout.store'), ['package_code' => 'impulso', 'amount_pen' => '0.01', 'credits' => 999]);

        $recharge = RechargeRequest::firstOrFail();
        $response->assertRedirect(route('teacher.credits.checkout.show', $recharge));
        $this->assertSame('culqi', $recharge->payment_method);
        $this->assertSame('pending', $recharge->status);
        $this->assertSame(15, $recharge->credits);
        $this->assertSame('30.00', (string) $recharge->amount_pen);
        $this->assertNull($recharge->operation_number);
        $this->assertSame($profile->id, $recharge->teacher_profile_id);
    }

    public function test_store_rejects_unknown_packages_and_is_a_503_when_culqi_is_misconfigured(): void
    {
        [$user] = $this->teacher();

        $this->actingAs($user)->post(route('teacher.credits.checkout.store'), ['package_code' => 'gratis'])->assertSessionHasErrors('package_code');

        config(['payments.culqi.private_key' => 'tu_llave_privada']);
        $this->actingAs($user)->post(route('teacher.credits.checkout.store'), ['package_code' => 'inicio'])->assertStatus(503);
        $this->assertSame(0, RechargeRequest::count());
    }

    public function test_show_exposes_the_public_key_only_and_the_server_amount(): void
    {
        [$user] = $this->teacher();
        $recharge = $this->recharge($user->teacherProfile, '30.00', 15);

        $response = $this->actingAs($user)->get(route('teacher.credits.checkout.show', $recharge));

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('Teacher/Credits/CheckoutCulqi')
            ->where('recharge.amount_minor', 3000)
            ->where('recharge.credits', 15)
            ->where('culqi.publicKey', 'pk_test_AbCdEf0123456789')
            ->where('culqi.methods', ['card', 'yape', 'order'])
            ->where('culqi.mode', 'test')
            ->where('initialStatus.status', 'idle'));

        $this->assertStringNotContainsString('sk_test_ZyXwVu9876543210', $response->getContent());
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
    }

    public function test_another_teacher_cannot_view_pay_or_poll_a_recharge_that_is_not_theirs(): void
    {
        [$owner] = $this->teacher();
        [$intruder] = $this->teacher();
        $recharge = $this->recharge($owner->teacherProfile);
        $this->fakeCulqi(postCharge: fn (Request $r) => Http::response($this->chargeFor($r->data()), 201));

        $this->actingAs($intruder)->get(route('teacher.credits.checkout.show', $recharge))->assertForbidden();
        $this->actingAs($intruder)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => self::TOKEN])->assertForbidden();
        $this->actingAs($intruder)->postJson(route('teacher.credits.checkout.refresh', $recharge))->assertForbidden();
        $this->actingAs($intruder)->getJson(route('teacher.credits.checkout.status', $recharge))->assertForbidden();
        // Las rutas de 3DS y de órdenes también validan la propiedad antes de hablar con Culqi.
        $this->actingAs($intruder)->postJson(route('teacher.credits.checkout.three-ds', $recharge), ['parameters3DS' => ['eci' => '05']])->assertForbidden();
        $this->actingAs($intruder)->postJson(route('teacher.credits.checkout.three-ds.cancel', $recharge))->assertForbidden();
        $this->actingAs($intruder)->postJson(route('teacher.credits.checkout.order', $recharge))->assertForbidden();

        $this->assertSame(0, $this->culqiCalls['POST /v2/charges']);
        $this->assertSame(0, PaymentOrder::count());
    }

    // ── Tarjeta ───────────────────────────────────────────────────────────

    public function test_an_approved_card_payment_credits_exactly_once_and_the_ui_only_says_approved_from_the_ledger(): void
    {
        [$user, $profile] = $this->teacher();
        $recharge = $this->recharge($profile, '30.00', 15);
        $this->fakeCulqi(postCharge: fn (Request $r) => Http::response($this->chargeFor($r->data()), 201));

        $this->actingAs($user)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => self::TOKEN, 'device_fingerprint_id' => self::DEVICE])
            ->assertOk()
            ->assertJson(['status' => 'approved', 'credits_credited' => true])
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertSame('approved', $recharge->fresh()->status);
        $this->assertSame(15, $profile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::where('recharge_request_id', $recharge->id)->where('type', 'deposit')->count());

        // Repetir el pago, el refresh o el status no puede acreditar de nuevo ni crear otro cargo.
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => 'tkn_test_OtherToken000001'])->assertOk()->assertJson(['status' => 'approved']);
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.refresh', $recharge))->assertOk()->assertJson(['status' => 'approved']);
        $this->assertSame(1, $this->culqiCalls['POST /v2/charges']);
        $this->assertSame(15, $profile->fresh()->credits_available);
        $this->assertFinancialInvariantsHold();
    }

    public function test_the_charge_request_to_culqi_uses_server_side_amount_email_metadata_and_the_device_fingerprint(): void
    {
        [$user, $profile] = $this->teacher('Ana Pérez Quispe', '987654321');
        $recharge = $this->recharge($profile, '10.00', 5);
        $this->fakeCulqi(postCharge: fn (Request $r) => Http::response($this->chargeFor($r->data()), 201));

        $this->actingAs($user)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => self::TOKEN, 'device_fingerprint_id' => self::DEVICE])->assertOk();

        $sent = $this->chargePayloads[0];
        $order = PaymentOrder::firstOrFail();
        $this->assertSame(1000, $sent['amount']);
        $this->assertSame('PEN', $sent['currency_code']);
        $this->assertSame($user->email, $sent['email']);
        $this->assertSame(self::TOKEN, $sent['source_id']);
        $this->assertTrue($sent['capture']);
        $this->assertSame(['first_name' => 'Ana', 'last_name' => 'Pérez Quispe', 'phone_number' => '987654321', 'device_finger_print_id' => self::DEVICE], $sent['antifraud_details']);
        $this->assertSame((string) $order->id, $sent['metadata']['payment_order_id']);
        $this->assertSame((string) $recharge->id, $sent['metadata']['recharge_request_id']);
        $this->assertSame($order->idempotency_key, $sent['metadata']['idempotency_key']);
        $this->assertSame('card', $order->payment_channel);
        $this->assertSame(hash('sha256', self::TOKEN), $order->source_token_hash);
        $this->assertArrayNotHasKey('authentication_3DS', $sent);
    }

    public function test_amounts_credits_currency_status_and_raw_card_fields_from_the_browser_are_rejected_before_any_call(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class); // 11 intentos seguidos: el límite por minuto no es lo que se prueba
        [$user, $profile] = $this->teacher();
        $recharge = $this->recharge($profile, '30.00', 15);
        $this->fakeCulqi(postCharge: fn (Request $r) => Http::response($this->chargeFor($r->data()), 201));

        foreach ([
            ['amount' => 1], ['amount_minor' => 1], ['amount_pen' => '0.01'], ['credits' => 9999], ['currency' => 'USD'], ['status' => 'approved'],
            ['package_code' => 'pro'], ['card_number' => '4111111111111111'], ['cvv' => '123'], ['expiration_month' => '09'], ['otp' => '123456'],
        ] as $extra) {
            $this->actingAs($user)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => self::TOKEN] + $extra)
                ->assertStatus(422)->assertJsonValidationErrors(array_key_first($extra));
        }

        $this->assertSame(0, $this->culqiCalls['POST /v2/charges']);
        $this->assertSame(0, PaymentOrder::count());
        $this->assertSame('pending', $recharge->fresh()->status);
    }

    public function test_malformed_or_foreign_environment_tokens_are_rejected(): void
    {
        [$user, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);
        $this->fakeCulqi();

        foreach (['', 'abc', 'tkn_live_AbCdEf0123456789', '4111111111111111', 'chr_test_AbCdEf0123456789', str_repeat('a', 80)] as $token) {
            $this->actingAs($user)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => $token])->assertStatus(422);
        }

        $this->assertSame(0, $this->culqiCalls['POST /v2/charges']);
    }

    public function test_a_declined_card_shows_a_failure_and_allows_a_new_attempt_without_credits(): void
    {
        [$user, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);
        $this->fakeCulqi(postCharge: fn () => Http::response($this->declinedError('chr_test_DECLINED00000001'), 400));

        $this->actingAs($user)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => self::TOKEN])
            ->assertOk()->assertJson(['status' => 'failed', 'credits_credited' => false]);
        $this->assertSame(0, $profile->fresh()->credits_available);

        $this->fakeCulqi(postCharge: fn (Request $r) => Http::response($this->chargeFor($r->data()), 201));
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => 'tkn_test_SecondTry0000001'])
            ->assertOk()->assertJson(['status' => 'approved']);

        $this->assertSame([1, 2], PaymentOrder::orderBy('attempt_number')->pluck('attempt_number')->all());
        $this->assertSame(1, CreditTransaction::where('recharge_request_id', $recharge->id)->where('type', 'deposit')->count());
    }

    public function test_a_network_error_is_reported_as_uncertain_never_as_failed_and_is_not_retried(): void
    {
        [$user, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);
        $this->fakeCulqi(postCharge: fn () => throw new ConnectionException('timeout'), listCharges: fn () => Http::response(['data' => []], 200));

        $this->actingAs($user)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => self::TOKEN])
            ->assertOk()->assertJson(['status' => 'uncertain', 'credits_credited' => false]);

        // El usuario insiste (doble clic / recarga): NO sale un segundo cobro.
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => 'tkn_test_RetryToken000001'])
            ->assertOk()->assertJson(['status' => 'uncertain']);

        $this->assertSame(1, $this->culqiCalls['POST /v2/charges']);
        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertSame(1, PaymentOrder::count());
    }

    public function test_a_double_click_while_the_charge_is_in_flight_sends_a_single_charge(): void
    {
        [$user, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);
        $second = null;
        $this->fakeCulqi(
            postCharge: function (Request $r) use ($user, $recharge, &$second) {
                // Mientras "vuela" el primer POST llega el segundo clic.
                $second = $this->actingAs($user)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => 'tkn_test_DoubleClick000001']);

                return Http::response($this->chargeFor($r->data()), 201);
            },
            listCharges: fn () => Http::response(['data' => []], 200),
        );

        $this->actingAs($user)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => self::TOKEN])->assertOk()->assertJson(['status' => 'approved']);

        $this->assertSame(1, $this->culqiCalls['POST /v2/charges']);
        $this->assertNotNull($second);
        $this->assertSame(1, CreditTransaction::where('recharge_request_id', $recharge->id)->where('type', 'deposit')->count());
    }

    // ── 3DS ───────────────────────────────────────────────────────────────

    public function test_3ds_flow_authenticates_then_charges_with_the_same_token_and_device_and_credits_once(): void
    {
        [$user, $profile] = $this->teacher();
        $recharge = $this->recharge($profile, '30.00', 15);
        $this->fakeCulqi(postCharge: function (Request $r) {
            return isset($r->data()['authentication_3DS'])
                ? Http::response($this->chargeFor($r->data()), 201)
                : Http::response($this->threeDsRequired(), 200);
        });

        // Paso 1: Culqi pide 3DS → el intento queda esperando; NO hay cargo ni créditos.
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => self::TOKEN, 'device_fingerprint_id' => self::DEVICE])
            ->assertOk()
            ->assertJson(['status' => 'pending', 'credits_credited' => false, 'action_required' => ['type' => '3ds', 'amount_minor' => 3000, 'email' => $user->email]]);
        $order = PaymentOrder::firstOrFail();
        $this->assertSame('awaiting_3ds', $order->submission_status);
        $this->assertNull($order->provider_order_id);
        $this->assertSame(0, $profile->fresh()->credits_available);

        // Paso 2: el navegador entrega los parameters3DS del banco.
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.three-ds', $recharge), ['token' => self::TOKEN, 'device_fingerprint_id' => self::DEVICE, 'parameters3DS' => $this->parameters3DS()])
            ->assertOk()->assertJson(['status' => 'approved', 'credits_credited' => true]);

        $second = $this->chargePayloads[1];
        $this->assertSame(self::TOKEN, $second['source_id']);
        $this->assertSame(self::DEVICE, $second['antifraud_details']['device_finger_print_id']);
        $this->assertSame($this->parameters3DS(), $second['authentication_3DS']);
        $this->assertSame($this->chargePayloads[0]['amount'], $second['amount']);
        $this->assertSame($this->chargePayloads[0]['metadata'], $second['metadata']);
        $this->assertSame(15, $profile->fresh()->credits_available);
        $this->assertSame(1, PaymentOrder::count(), 'El 3DS no crea un segundo intento.');

        // Repetir el paso 2 no cobra ni acredita otra vez.
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.three-ds', $recharge), ['token' => self::TOKEN, 'device_fingerprint_id' => self::DEVICE, 'parameters3DS' => $this->parameters3DS()])
            ->assertOk()->assertJson(['status' => 'approved']);
        $this->assertSame(2, $this->culqiCalls['POST /v2/charges']);
        $this->assertSame(15, $profile->fresh()->credits_available);
        $this->assertFinancialInvariantsHold();
    }

    public function test_3ds_second_step_refuses_a_different_token_or_device_and_malformed_parameters(): void
    {
        [$user, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);
        $this->fakeCulqi(postCharge: fn (Request $r) => isset($r->data()['authentication_3DS']) ? Http::response($this->chargeFor($r->data()), 201) : Http::response($this->threeDsRequired(), 200));
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => self::TOKEN, 'device_fingerprint_id' => self::DEVICE])->assertJson(['status' => 'pending']);

        $step = fn (array $override) => $this->actingAs($user)->postJson(route('teacher.credits.checkout.three-ds', $recharge), $override + ['token' => self::TOKEN, 'device_fingerprint_id' => self::DEVICE, 'parameters3DS' => $this->parameters3DS()]);

        $step(['token' => 'tkn_test_StolenToken000001'])->assertOk()->assertJson(['status' => 'pending']);
        $step(['device_fingerprint_id' => '11111111-2222-3333-4444-555555555555'])->assertOk()->assertJson(['status' => 'pending']);
        $step(['parameters3DS' => ['eci' => 'ZZ'] + $this->parameters3DS()])->assertStatus(422);
        $step(['parameters3DS' => ['xid' => 'x'] + $this->parameters3DS()])->assertOk()->assertJson(['status' => 'pending']);

        $this->assertSame(1, $this->culqiCalls['POST /v2/charges'], 'Ningún intento inválido de completar el 3DS llegó a Culqi.');
        $this->assertSame(0, $profile->fresh()->credits_available);
    }

    public function test_cancelling_3ds_closes_the_attempt_without_a_charge_and_allows_a_new_attempt(): void
    {
        [$user, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);
        $this->fakeCulqi(postCharge: fn (Request $r) => isset($r->data()['authentication_3DS']) ? Http::response($this->chargeFor($r->data()), 201) : Http::response($this->threeDsRequired(), 200));
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => self::TOKEN, 'device_fingerprint_id' => self::DEVICE])->assertJson(['status' => 'pending']);

        // Mientras el 3DS está en curso no se admite otro cobro.
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => 'tkn_test_Concurrent0000001'])->assertJson(['status' => 'pending']);
        $this->assertSame(1, $this->culqiCalls['POST /v2/charges']);

        $this->actingAs($user)->postJson(route('teacher.credits.checkout.three-ds.cancel', $recharge), ['reason' => 'cancelled'])
            ->assertOk()->assertJson(['status' => 'failed', 'credits_credited' => false]);
        $this->assertStringContainsString('Cancelaste la verificación', $this->actingAs($user)->getJson(route('teacher.credits.checkout.status', $recharge))->json('message'));
        $this->assertSame(0, $profile->fresh()->credits_available);

        $this->fakeCulqi(postCharge: fn (Request $r) => Http::response($this->chargeFor($r->data()), 201));
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => 'tkn_test_Fresh0000000001'])->assertJson(['status' => 'approved']);
        $this->assertSame(1, CreditTransaction::where('recharge_request_id', $recharge->id)->where('type', 'deposit')->count());
    }

    public function test_an_expired_3ds_window_closes_by_itself_and_the_late_second_step_is_refused(): void
    {
        [$user, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);
        $this->fakeCulqi(postCharge: fn (Request $r) => isset($r->data()['authentication_3DS']) ? Http::response($this->chargeFor($r->data()), 201) : Http::response($this->threeDsRequired(), 200));
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => self::TOKEN, 'device_fingerprint_id' => self::DEVICE])->assertJson(['status' => 'pending']);

        $this->travel(15)->minutes();

        $this->actingAs($user)->postJson(route('teacher.credits.checkout.three-ds', $recharge), ['token' => self::TOKEN, 'device_fingerprint_id' => self::DEVICE, 'parameters3DS' => $this->parameters3DS()])
            ->assertOk()->assertJson(['status' => 'failed']);
        $this->assertSame(1, $this->culqiCalls['POST /v2/charges']);
        $this->assertSame(0, $profile->fresh()->credits_available);
    }

    public function test_a_second_3ds_request_or_a_yape_token_without_device_never_loops(): void
    {
        [$user, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);
        $this->fakeCulqi(postCharge: fn () => Http::response($this->threeDsRequired(), 200));

        // Sin device_fingerprint_id el 3DS no es posible: el intento termina (no queda esperando algo imposible).
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => self::TOKEN])->assertJson(['status' => 'failed']);

        $recharge2 = $this->recharge($profile);
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.pay', $recharge2), ['token' => self::TOKEN, 'device_fingerprint_id' => self::DEVICE])->assertJson(['status' => 'pending']);
        // Si el segundo POST (ya con authentication_3DS) vuelve a pedir 3DS, termina: no hay bucle.
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.three-ds', $recharge2), ['token' => self::TOKEN, 'device_fingerprint_id' => self::DEVICE, 'parameters3DS' => $this->parameters3DS()])
            ->assertOk()->assertJson(['status' => 'failed']);
        $this->assertSame(0, $profile->fresh()->credits_available);
    }

    // ── Yape ──────────────────────────────────────────────────────────────

    public function test_yape_tokens_are_identified_by_prefix_on_the_server_and_only_when_the_method_is_enabled(): void
    {
        [$user, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);
        $this->fakeCulqi(postCharge: fn (Request $r) => Http::response($this->chargeFor($r->data()), 201));

        config(['payments.culqi.methods' => 'card']);
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => 'ype_test_AbCdEf0123456789'])->assertStatus(422);
        $this->assertSame(0, $this->culqiCalls['POST /v2/charges']);

        config(['payments.culqi.methods' => 'card,yape']);
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => 'ype_test_AbCdEf0123456789'])->assertJson(['status' => 'approved']);
        $this->assertSame('yape', PaymentOrder::firstOrFail()->payment_channel);
        $this->assertArrayNotHasKey('device_finger_print_id', $this->chargePayloads[0]['antifraud_details'] ?? [], 'Yape no lleva device fingerprint.');
        $this->assertArrayNotHasKey('authentication_3DS', $this->chargePayloads[0], 'Yape no usa 3DS.');
    }

    // ── Órdenes de pago (PagoEfectivo, billeteras…) ───────────────────────

    public function test_creating_an_order_never_credits_and_returns_what_the_checkout_needs_to_show_the_payment_code(): void
    {
        [$user, $profile] = $this->teacher('Ana Pérez Quispe', '987654321');
        $recharge = $this->recharge($profile, '30.00', 15);
        $this->fakeCulqi(postOrder: fn (Request $r) => Http::response($this->orderFor($r->data()), 201));

        $json = $this->actingAs($user)->postJson(route('teacher.credits.checkout.order', $recharge))->assertOk()->json();

        $this->assertSame('pending', $json['status']);
        $this->assertFalse($json['credits_credited']);
        $this->assertSame('order', $json['action_required']['type']);
        $this->assertStringStartsWith('ord_test_', $json['action_required']['order_id']);
        $this->assertSame(3000, $json['action_required']['amount_minor']);

        $sent = $this->orderPayloads[0];
        $order = PaymentOrder::firstOrFail();
        $this->assertSame(3000, $sent['amount']);
        $this->assertFalse($sent['confirm']);
        $this->assertLessThanOrEqual(36, strlen($sent['order_number']));
        $this->assertSame(['first_name' => 'Ana', 'last_name' => 'Pérez Quispe', 'email' => $user->email, 'phone_number' => '987654321'], $sent['client_details']);
        $this->assertSame((string) $order->id, $sent['metadata']['payment_order_id']);
        $this->assertSame('order', $order->payment_channel);
        $this->assertSame($json['action_required']['order_id'], $order->provider_order_id);
        $this->assertNotNull($order->expires_at);
        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertSame('pending', $recharge->fresh()->status);

        // No se admite otro intento (ni tarjeta) mientras la orden siga viva.
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => self::TOKEN])->assertOk()->assertJson(['status' => 'pending']);
        $this->assertSame(0, $this->culqiCalls['POST /v2/charges']);
        $this->assertSame(1, PaymentOrder::count());
    }

    public function test_an_order_needs_a_phone_number_and_an_enabled_method(): void
    {
        [$user, $profile] = $this->teacher('Sin Celular', null);
        $recharge = $this->recharge($profile);
        $this->fakeCulqi(postOrder: fn (Request $r) => Http::response($this->orderFor($r->data()), 201));

        $this->actingAs($user)->postJson(route('teacher.credits.checkout.order', $recharge))->assertStatus(422)->assertJsonFragment(['message' => 'Registra tu celular en tu perfil para pagar en efectivo o con billetera: Culqi lo exige para generar la orden.']);

        $user->update(['phone' => '987654321']);
        config(['payments.culqi.methods' => 'card']);
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.order', $recharge))->assertStatus(422);
        $this->assertSame(0, $this->culqiCalls['POST /v2/orders']);
        $this->assertSame(0, PaymentOrder::count());
    }

    public function test_a_paid_order_notification_credits_once_even_if_the_user_closed_the_page_and_events_repeat(): void
    {
        [$user, $profile] = $this->teacher('Ana Pérez', '987654321');
        $recharge = $this->recharge($profile, '30.00', 15);
        $created = null;
        $this->fakeCulqi(postOrder: function (Request $r) use (&$created) {
            $created = $this->orderFor($r->data());

            return Http::response($created, 201);
        });
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.order', $recharge))->assertOk();
        $orderId = $created['id'];

        // Mientras no hay pago, un evento "pending" no acredita.
        $this->fakeCulqi(getOrder: fn () => Http::response(array_replace($created, ['state' => 'pending', 'payment_code' => '13642064']), 200));
        $this->deliver($this->culqiEvent('evt_test_o1', 'order.status.changed', $orderId))->assertOk();
        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertSame('pending', PaymentOrder::firstOrFail()->status);

        // Se paga (el usuario ya cerró la página): evento + verdad de la API → acredita una sola vez.
        $paid = array_replace($created, ['state' => 'paid', 'paid_at' => time(), 'payment_code' => '13642064']);
        $this->fakeCulqi(getOrder: fn () => Http::response($paid, 200));
        foreach (['evt_test_o2', 'evt_test_o3', 'evt_test_o2'] as $id) {
            $this->deliver($this->culqiEvent($id, 'order.status.changed', $orderId))->assertOk();
        }

        $this->assertSame('paid', PaymentOrder::firstOrFail()->status);
        $this->assertSame('approved', $recharge->fresh()->status);
        $this->assertSame(15, $profile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::where('recharge_request_id', $recharge->id)->where('type', 'deposit')->count());
        $this->assertSame(3, PaymentWebhook::where('provider', 'culqi')->count(), 'El evento repetido (evt_test_o2) se persiste una sola vez.');
        $this->assertFinancialInvariantsHold();

        // El usuario vuelve a la página: ve aprobado porque el ledger lo dice.
        $this->actingAs($user)->getJson(route('teacher.credits.checkout.status', $recharge))->assertJson(['status' => 'approved', 'credits_credited' => true]);
    }

    public function test_an_order_event_claiming_paid_does_not_credit_when_the_api_says_the_order_is_not_paid(): void
    {
        [$user, $profile] = $this->teacher('Ana Pérez', '987654321');
        $recharge = $this->recharge($profile);
        $created = null;
        $this->fakeCulqi(postOrder: function (Request $r) use (&$created) {
            $created = $this->orderFor($r->data());

            return Http::response($created, 201);
        });
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.order', $recharge))->assertOk();
        $this->fakeCulqi(getOrder: fn () => Http::response($created, 200)); // sigue "created"

        $this->deliver($this->culqiEvent('evt_test_liar', 'order.status.changed', $created['id']))->assertOk();

        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertSame('pending', $recharge->fresh()->status);
    }

    public function test_an_expired_order_ends_the_attempt_without_credits_and_a_new_one_can_start(): void
    {
        [$user, $profile] = $this->teacher('Ana Pérez', '987654321');
        $recharge = $this->recharge($profile);
        $created = null;
        $this->fakeCulqi(postOrder: function (Request $r) use (&$created) {
            $created = $this->orderFor($r->data());

            return Http::response($created, 201);
        });
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.order', $recharge))->assertOk();

        $this->fakeCulqi(getOrder: fn () => Http::response(array_replace($created, ['state' => 'expired']), 200));
        $this->deliver($this->culqiEvent('evt_test_exp', 'order.status.changed', $created['id']))->assertOk();

        $this->assertSame('expired', PaymentOrder::firstOrFail()->status);
        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->actingAs($user)->getJson(route('teacher.credits.checkout.status', $recharge))->assertJson(['status' => 'failed']);

        $this->fakeCulqi(postCharge: fn (Request $r) => Http::response($this->chargeFor($r->data()), 201));
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => self::TOKEN])->assertJson(['status' => 'approved']);
        $this->assertSame(2, PaymentOrder::count());
    }

    public function test_an_order_with_a_different_amount_or_foreign_metadata_goes_to_review_and_never_credits(): void
    {
        [$user, $profile] = $this->teacher('Ana Pérez', '987654321');
        $recharge = $this->recharge($profile, '30.00', 15);
        $created = null;
        $this->fakeCulqi(postOrder: function (Request $r) use (&$created) {
            $created = $this->orderFor($r->data());

            return Http::response($created, 201);
        });
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.order', $recharge))->assertOk();

        $paidWrongAmount = array_replace($created, ['state' => 'paid', 'paid_at' => time(), 'amount' => 100]);
        $this->fakeCulqi(getOrder: fn () => Http::response($paidWrongAmount, 200));
        $this->deliver($this->culqiEvent('evt_test_amt', 'order.status.changed', $created['id']))->assertOk();
        $this->assertNotNull(PaymentOrder::firstOrFail()->review_reason);

        $paidForeign = array_replace($created, ['state' => 'paid', 'paid_at' => time(), 'metadata' => ['payment_order_id' => '999', 'recharge_request_id' => '999', 'idempotency_key' => 'otro']]);
        $this->fakeCulqi(getOrder: fn () => Http::response($paidForeign, 200));
        $this->deliver($this->culqiEvent('evt_test_meta', 'order.status.changed', $created['id']))->assertOk();

        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertSame('pending', $recharge->fresh()->status);
    }

    public function test_an_order_state_paid_without_paid_at_or_unknown_states_are_never_credited(): void
    {
        $this->assertSame('review', \App\Payment\Culqi\CulqiOrderMapper::normalize(['state' => 'paid', 'paid_at' => null]));
        $this->assertSame('review', \App\Payment\Culqi\CulqiOrderMapper::normalize(['state' => 'pending', 'paid_at' => 1792640277]));
        $this->assertSame('review', \App\Payment\Culqi\CulqiOrderMapper::normalize(['state' => 'raro']));
        $this->assertSame('pending', \App\Payment\Culqi\CulqiOrderMapper::normalize(['state' => 'created', 'paid_at' => null]));
        $this->assertSame('paid', \App\Payment\Culqi\CulqiOrderMapper::normalize(['state' => 'paid', 'paid_at' => 1792640277]));
        $this->assertSame('cancelled', \App\Payment\Culqi\CulqiOrderMapper::normalize(['state' => 'deleted']));
    }

    public function test_an_order_whose_creation_response_was_lost_is_linked_by_metadata_when_the_payment_event_arrives(): void
    {
        [$user, $profile] = $this->teacher('Ana Pérez', '987654321');
        $recharge = $this->recharge($profile, '30.00', 15);
        $created = null;
        $this->fakeCulqi(postOrder: function (Request $r) use (&$created) {
            $created = $this->orderFor($r->data());

            throw new ConnectionException('timeout después de crear la orden');
        });
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.order', $recharge))->assertOk()->assertJson(['status' => 'uncertain']);
        $local = PaymentOrder::firstOrFail();
        $this->assertNull($local->provider_order_id);
        $this->assertSame(1, $this->culqiCalls['POST /v2/orders']);

        $paid = array_replace($created, ['state' => 'paid', 'paid_at' => time()]);
        $this->fakeCulqi(getOrder: fn () => Http::response($paid, 200));
        $this->deliver($this->culqiEvent('evt_test_link', 'order.status.changed', $created['id']))->assertOk();

        $this->assertSame($created['id'], $local->fresh()->provider_order_id);
        $this->assertSame('approved', $recharge->fresh()->status);
        $this->assertSame(15, $profile->fresh()->credits_available);
        $this->assertSame(1, $this->culqiCalls['POST /v2/orders'], 'La creación de la orden no se reintentó.');
    }

    // ── Estado, polling y conciliación ────────────────────────────────────

    public function test_get_status_is_read_only_and_refresh_reconciles_a_pending_order_against_the_api(): void
    {
        [$user, $profile] = $this->teacher('Ana Pérez', '987654321');
        $recharge = $this->recharge($profile, '30.00', 15);
        $created = null;
        $this->fakeCulqi(postOrder: function (Request $r) use (&$created) {
            $created = $this->orderFor($r->data());

            return Http::response($created, 201);
        });
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.order', $recharge))->assertOk();

        $paid = array_replace($created, ['state' => 'paid', 'paid_at' => time()]);
        $this->fakeCulqi(getOrder: fn () => Http::response($paid, 200));
        $callsBefore = array_sum($this->culqiCalls);

        $this->actingAs($user)->getJson(route('teacher.credits.checkout.status', $recharge))->assertJson(['status' => 'pending']);
        $this->assertSame($callsBefore, array_sum($this->culqiCalls), 'GET status no llama a Culqi ni muta nada.');

        $this->actingAs($user)->postJson(route('teacher.credits.checkout.refresh', $recharge))->assertJson(['status' => 'approved', 'credits_credited' => true]);
        $this->assertSame(15, $profile->fresh()->credits_available);

        $this->actingAs($user)->postJson(route('teacher.credits.checkout.refresh', $recharge))->assertJson(['status' => 'approved']);
        $this->assertSame(15, $profile->fresh()->credits_available);
        $this->assertFinancialInvariantsHold();
    }

    public function test_a_worker_that_dies_while_crediting_loses_nothing_and_the_retry_credits_exactly_once(): void
    {
        [$user, $profile] = $this->teacher();
        $recharge = $this->recharge($profile, '30.00', 15);
        $this->fakeCulqi(postCharge: fn (Request $r) => Http::response($this->chargeFor($r->data()), 201));

        // La primera acreditación "muere" a mitad; la transacción de la conciliación se revierte entera.
        $dying = \Mockery::mock(RechargeApprovalService::class)->makePartial();
        $dying->shouldReceive('credit')->once()->andThrow(new \RuntimeException('worker killed'));
        $this->app->instance(RechargeApprovalService::class, $dying);

        $this->actingAs($user)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => self::TOKEN, 'device_fingerprint_id' => self::DEVICE]);

        $order = PaymentOrder::firstOrFail();
        $this->assertSame(0, CreditTransaction::where('recharge_request_id', $recharge->id)->count());
        $this->assertSame('pending', $recharge->fresh()->status);
        $this->assertNotSame('paid', $order->status, 'Sin acreditar no puede quedar marcada como pagada.');
        $this->assertSame($this->chargePayloads[0]['metadata']['payment_order_id'], (string) $order->id);

        // Reintento (barrido / webhook): la verdad de Culqi acredita una sola vez.
        $this->app->forgetInstance(RechargeApprovalService::class);
        $this->app->instance(RechargeApprovalService::class, new RechargeApprovalService());
        $charge = $this->chargeFor($this->chargePayloads[0], ['id' => $order->provider_order_id ?? 'chr_test_RETRY0000000001']);
        $order->update(['provider_order_id' => $charge['id'], 'submission_status' => 'submitted']);
        $this->fakeCulqi(getCharge: fn () => Http::response($charge, 200));

        $this->artisan('culqi:reconcile', ['--order' => $order->id])->assertExitCode(0);
        $this->artisan('culqi:reconcile', ['--order' => $order->id])->assertExitCode(0);

        $this->assertSame('approved', $recharge->fresh()->status);
        $this->assertSame(15, $profile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::where('recharge_request_id', $recharge->id)->where('type', 'deposit')->count());
    }

    public function test_a_payment_confirmed_for_user_a_can_never_credit_user_b(): void
    {
        [$userA, $profileA] = $this->teacher();
        [$userB, $profileB] = $this->teacher();
        $rechargeA = $this->recharge($profileA, '30.00', 15);
        $rechargeB = $this->recharge($profileB, '30.00', 15);
        $this->fakeCulqi(postCharge: fn (Request $r) => Http::response($this->chargeFor($r->data()), 201));
        $this->actingAs($userA)->postJson(route('teacher.credits.checkout.pay', $rechargeA), ['token' => self::TOKEN])->assertJson(['status' => 'approved']);
        $orderA = PaymentOrder::where('recharge_request_id', $rechargeA->id)->firstOrFail();

        // Un evento/cargo del usuario A asociado a la orden de B no puede acreditar a B.
        $orderB = PaymentOrder::create([
            'recharge_request_id' => $rechargeB->id, 'attempt_number' => 1, 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'provider' => 'culqi',
            'payment_channel' => 'card', 'provider_order_id' => $orderA->provider_order_id.'x', 'submission_status' => 'submitted', 'status' => 'pending', 'amount_minor' => 3000, 'currency' => 'PEN',
        ]);
        $chargeOfA = $this->chargeFor($this->chargePayloads[0], ['id' => $orderB->provider_order_id]);
        $this->assertSame('review', app(\App\Services\CulqiPaymentReconciliationService::class)->applyTruth($orderB, null, $chargeOfA));

        $this->assertSame(0, $profileB->fresh()->credits_available);
        $this->assertSame(15, $profileA->fresh()->credits_available);
    }

    // ── Devoluciones ──────────────────────────────────────────────────────

    public function test_a_confirmed_full_refund_reverses_even_if_the_credits_were_already_spent_following_the_documented_rule(): void
    {
        [$user, $profile] = $this->teacher();
        $recharge = $this->recharge($profile, '30.00', 15);
        $this->fakeCulqi(postCharge: fn (Request $r) => Http::response($this->chargeFor($r->data()), 201));
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => self::TOKEN])->assertJson(['status' => 'approved']);
        $order = PaymentOrder::firstOrFail();

        // El profesor ya consumió 10 de los 15 créditos (clases dictadas).
        $profile->fresh()->update(['credits_available' => 5]);

        $refunded = $this->chargeFor($this->chargePayloads[0], ['id' => $order->provider_order_id, 'amount_refunded' => 3000, 'current_amount' => 0]);
        $this->fakeCulqi(getCharge: fn () => Http::response($refunded, 200));
        $this->deliver($this->culqiEvent('evt_test_ref', 'refund.creation.succeeded', 'ref_test_000000000001', ['id' => 'ref_test_000000000001', 'charge_id' => $order->provider_order_id]))->assertOk();
        $this->deliver($this->culqiEvent('evt_test_ref2', 'refund.creation.succeeded', 'ref_test_000000000001', ['id' => 'ref_test_000000000001', 'charge_id' => $order->provider_order_id]))->assertOk();

        // docs/release/MOVA_V1_DECISIONS.md §3: la reversión confirmada por el proveedor deja el saldo negativo
        // (el profesor queda restringido para nuevas reservas); el ledger conserva el depósito y añade la reversión.
        $this->assertSame('reversed', $recharge->fresh()->status);
        $this->assertSame(-10, $profile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::where('recharge_request_id', $recharge->id)->where('type', 'deposit')->count());
        $this->assertSame(1, CreditTransaction::where('recharge_request_id', $recharge->id)->where('type', 'reversal')->count());
        $this->actingAs($user)->getJson(route('teacher.credits.checkout.status', $recharge))->assertJson(['status' => 'failed', 'credits_credited' => false]);
    }

    // ── Convivencia con Mercado Pago ──────────────────────────────────────

    public function test_mercado_pago_recharges_keep_their_provider_and_are_not_touched_by_the_culqi_paths(): void
    {
        [$user, $profile] = $this->teacher();
        $mp = $this->recharge($profile, '10.00', 5, 'mercadopago');
        $mpOrder = PaymentOrder::create([
            'recharge_request_id' => $mp->id, 'attempt_number' => 1, 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'provider' => 'mercadopago',
            'provider_order_id' => '123456789', 'submission_status' => 'submitted', 'status' => 'pending', 'amount_minor' => 1000, 'currency' => 'PEN',
        ]);
        $this->fakeCulqi();

        // El estado de un pago histórico de MP se sigue sirviendo por el controlador de MP.
        $this->actingAs($user)->getJson(route('teacher.credits.checkout.status', $mp))->assertOk()->assertJsonStructure(['status', 'message', 'credits_credited', 'action_required']);
        // Los pasos propios de Culqi no existen para una recarga de MP.
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.three-ds', $mp), ['token' => self::TOKEN, 'device_fingerprint_id' => self::DEVICE, 'parameters3DS' => $this->parameters3DS()])->assertNotFound();
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.order', $mp))->assertNotFound();
        // Y con MP desactivado como proveedor, su checkout no cobra: nunca se redirige a Culqi.
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.pay', $mp), ['payment_method' => 'yape', 'token' => 'tok'])->assertStatus(503);

        $this->assertSame(0, array_sum($this->culqiCalls));
        $this->assertSame('mercadopago', $mpOrder->fresh()->provider);
        $this->assertSame('mercadopago', $mp->fresh()->payment_method);
    }

    public function test_the_active_checkout_provider_follows_the_configuration_and_the_credits_page_names_it(): void
    {
        [$user] = $this->teacher();

        $this->assertSame('culqi', CheckoutProvider::active($user));
        $this->actingAs($user)->get(route('teacher.credits.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('checkoutProvider', 'culqi')->where('mercadoPagoCheckoutEnabled', false));

        config(['payments.provider' => 'mercadopago', 'payments.mercadopago.public_key' => 'TEST-public-key']);
        $this->assertSame('mercadopago', CheckoutProvider::active($user));
        $this->actingAs($user)->get(route('teacher.credits.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('checkoutProvider', 'mercadopago')->where('mercadoPagoCheckoutEnabled', true));

        config(['payments.provider' => 'fake']);
        $this->assertNull(CheckoutProvider::active($user));
    }

    public function test_only_the_methods_enabled_in_configuration_are_offered(): void
    {
        $this->assertSame(['card', 'yape', 'order'], CheckoutProvider::culqiMethods());
        config(['payments.culqi.methods' => 'card']);
        $this->assertSame(['card'], CheckoutProvider::culqiMethods());
        config(['payments.culqi.methods' => ' Card , bitcoin ,order']);
        $this->assertSame(['card', 'order'], CheckoutProvider::culqiMethods());
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** @return array{0: User, 1: TeacherProfile} */
    private function teacher(string $name = 'Profesor MOVA', ?string $phone = '987654321'): array
    {
        $user = User::factory()->create(['name' => $name, 'phone' => $phone, 'password' => 'password']);
        $user->assignRole('teacher');
        LegalAcceptance::recordMissingCurrent($user, null);
        $profile = TeacherProfile::create(['user_id' => $user->id, 'is_verified' => true, 'credits_available' => 0, 'credits_reserved' => 0]);

        return [$user->fresh(), $profile];
    }

    private function recharge(TeacherProfile $profile, string $amountPen = '10.00', int $credits = 5, string $method = 'culqi'): RechargeRequest
    {
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

    /** Entrega un webhook (Culqi no firma: va con el secreto de la URL) y procesa el job en línea. */
    private function deliver(array $event)
    {
        $response = $this->postJson('/api/webhooks/culqi/'.self::SECRET, $event);

        foreach (PaymentWebhook::where('provider', 'culqi')->where('status', 'received')->get() as $webhook) {
            (new \App\Jobs\ProcessCulqiWebhook($webhook->id))->handle(app(\App\Services\CulqiPaymentReconciliationService::class));
        }

        return $response;
    }
}
