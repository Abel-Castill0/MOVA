<?php

namespace Tests\Feature;

use App\Models\PaymentOrder;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Frontera de aplicación del checkout automático (CreditCheckoutController)
 * — cubre exactamente lo que pide la sección 13 del encargo: autorización/
 * ownership, que amount/credits/currency/external_reference/payer nunca
 * lleguen desde el cliente, el instrumento Yape, el endpoint de estado, y
 * "aprobado exactamente una vez" incluso con polling repetido. No repite
 * la cobertura ya existente de MercadoPagoPaymentProviderTest (payload
 * exacto de Payments API, clasificación de errores HTTP, etc.) — esa sigue
 * siendo la fuente de verdad para el provider en sí.
 */
class CreditCheckoutControllerTest extends TestCase
{
    use RefreshDatabase;

    private const LAB_ACCESS_TOKEN = 'checkout-test-placeholder-000';
    private const LAB_PUBLIC_KEY = 'TEST-lab-public-key';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payments.enabled' => true,
            'payments.provider' => 'mercadopago',
            'payments.mercadopago.base_url' => 'https://api.mercadopago.com',
            'payments.mercadopago.access_token' => self::LAB_ACCESS_TOKEN,
            'payments.mercadopago.public_key' => self::LAB_PUBLIC_KEY,
            'payments.mercadopago.application_id' => '6583217782927097',
            'payments.mercadopago.expected_collector_id' => '123456789',
            'payments.mercadopago.expected_live_mode' => 'false',
            'payments.mercadopago.webhooks_enabled' => false,
        ]);
    }

    // ---- store() -------------------------------------------------------

    public function test_teacher_can_create_a_checkout_recharge_from_the_server_catalog_only(): void
    {
        [$teacher, $profile] = $this->teacher();

        $response = $this->actingAs($teacher)->post(route('teacher.credits.checkout.store'), [
            'package_code' => 'inicio',
            // Intento de inyectar precio/créditos/moneda desde el cliente —
            // deben ser ignorados por completo: el controller solo lee
            // package_code y resuelve todo lo demás de config('credits.packages').
            'amount_pen' => '0.01',
            'credits' => 99999,
            'currency' => 'USD',
        ]);

        $recharge = RechargeRequest::where('teacher_profile_id', $profile->id)->firstOrFail();

        $response->assertRedirect(route('teacher.credits.checkout.show', $recharge));
        $this->assertSame('mercadopago', $recharge->payment_method);
        $this->assertSame('10.00', $recharge->amount_pen);
        $this->assertSame(5, $recharge->credits);
        $this->assertSame('pending', $recharge->status);
        // P1 (MOVA Yape Checkout Pre-Card Hardening): ya no se inventa un
        // placeholder UUID para satisfacer la restricción heredada del
        // flujo manual — la columna es NULL a propósito (ver migración
        // 2026_09_02_000001_make_recharge_operation_number_nullable.php).
        $this->assertNull($recharge->operation_number);
        $this->assertNull($recharge->operation_number_normalized);
    }

    public function test_store_is_blocked_when_mercadopago_checkout_is_disabled(): void
    {
        config(['payments.enabled' => false]);
        [$teacher] = $this->teacher();

        $this->actingAs($teacher)
            ->post(route('teacher.credits.checkout.store'), ['package_code' => 'inicio'])
            ->assertStatus(503);
    }

    public function test_store_rejects_a_package_code_not_in_the_server_catalog(): void
    {
        [$teacher] = $this->teacher();

        $this->actingAs($teacher)
            ->post(route('teacher.credits.checkout.store'), ['package_code' => 'free-money'])
            ->assertSessionHasErrors('package_code');
    }

    // ---- ownership -------------------------------------------------------

    public function test_a_teacher_cannot_view_or_pay_another_teachers_recharge(): void
    {
        [, $ownerProfile] = $this->teacher();
        [$intruder] = $this->teacher();
        $recharge = $this->recharge($ownerProfile);

        $this->actingAs($intruder)->get(route('teacher.credits.checkout.show', $recharge))->assertForbidden();
        $this->actingAs($intruder)->post(route('teacher.credits.checkout.pay', $recharge), ['token' => 'x'])->assertForbidden();
        $this->actingAs($intruder)->get(route('teacher.credits.checkout.status', $recharge))->assertForbidden();
        $this->actingAs($intruder)->post(route('teacher.credits.checkout.refresh', $recharge))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        $this->get(route('teacher.credits.checkout.show', $recharge))->assertRedirect(route('login'));
    }

    // ---- pay() — instrumento Yape y campos ignorados del cliente ----------

    public function test_pay_builds_a_yape_instrument_and_ignores_any_financial_field_from_the_client(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response([
            'id' => 555, 'status' => 'in_process', 'status_detail' => 'pending_contingency',
        ], 201)]);

        [$teacher, $profile] = $this->teacher();
        $recharge = $this->recharge($profile, amountPen: '30.00', credits: 15);

        $response = $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.pay', $recharge), [
            'payment_method' => 'yape',
            'token' => 'yape-token-abc',
            // Nunca deben influir en el payload real enviado a Mercado Pago.
            'amount' => 1,
            'transaction_amount' => 1,
            'credits' => 999999,
            'external_reference' => 'attacker-controlled',
            'payer' => ['email' => 'attacker@example.com'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'pending');
        $response->assertJsonPath('credits_credited', false);

        Http::assertSent(function ($request) use ($recharge) {
            return $request['payment_method_id'] === 'yape'
                && $request['installments'] === 1
                && $request['token'] === 'yape-token-abc'
                && $request['transaction_amount'] === 30.0 // server-derivado, no el 1 del cliente
                && $request['external_reference'] === "recharge:{$recharge->id}:attempt:1"
                && ($request['payer']['email'] ?? null) !== 'attacker@example.com';
        });
    }

    /**
     * Regresión de un bug real encontrado en vivo durante el E2E de esta
     * ronda: sin un debounce PROPIO para reconcileUncertainSubmission()
     * (distinto del de reconcile()), un polling cada pocos segundos agotaba
     * el presupuesto de búsqueda (default 5 intentos) en menos de 20
     * segundos y el intento caía a 'review' aunque Mercado Pago pudiera
     * confirmar el pago poco después. Simula 10 polls "rápidos" seguidos
     * sobre un intento incierto y confirma que como mucho UNA búsqueda real
     * ocurrió (recovery_attempts <= 1), nunca las 10.
     */
    public function test_rapid_polling_never_burns_the_uncertain_search_budget(): void
    {
        [$teacher, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);
        PaymentOrder::create([
            'recharge_request_id' => $recharge->id,
            'attempt_number' => 1,
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'provider' => 'mercadopago',
            'provider_order_id' => null,
            'submission_status' => 'uncertain',
            'status' => 'pending',
            'amount_minor' => 1000,
            'currency' => 'PEN',
        ]);

        Http::fake(['api.mercadopago.com/*' => Http::response(['results' => []], 200)]);

        // STATUS SEMANTICS: la reconciliación (y por lo tanto el gasto del
        // presupuesto de búsqueda) vive en refresh() (POST), no en
        // status() (GET) — ver test_get_status_never_reconciles_or_mutates_state.
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.refresh', $recharge))->assertOk();
        }

        $order = PaymentOrder::where('recharge_request_id', $recharge->id)->firstOrFail();
        $this->assertLessThanOrEqual(1, $order->recovery_attempts);
        $this->assertNull($order->review_reason);
    }

    public function test_pay_requires_a_token(): void
    {
        [$teacher, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        $this->actingAs($teacher)
            ->postJson(route('teacher.credits.checkout.pay', $recharge), [])
            ->assertStatus(422);
    }

    public function test_pay_requires_an_explicit_payment_method(): void
    {
        [$teacher, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        // Sin 'payment_method' explícito no hay forma de que el controller
        // decida qué instrumento construir — nunca se infiere por la
        // presencia de otros campos (ver docblock de pay()).
        $this->actingAs($teacher)
            ->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => 'tok'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');
    }

    // ---- pay() — instrumento Card (Card Payment Brick) ---------------------

    public function test_pay_builds_a_card_instrument_and_ignores_any_financial_field_from_the_client(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response([
            'id' => 777, 'status' => 'in_process', 'status_detail' => 'pending_contingency',
        ], 201)]);

        [$teacher, $profile] = $this->teacher();
        $recharge = $this->recharge($profile, amountPen: '30.00', credits: 15);

        $response = $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.pay', $recharge), [
            'payment_method' => 'card',
            'token' => 'card-brick-token-abc',
            'payment_method_id' => 'visa',
            'installments' => 3,
            'issuer_id' => '310',
            'identification_type' => 'DNI',
            'identification_number' => '12345678',
            // Nunca deben influir en el payload real enviado a Mercado Pago.
            'amount' => 1,
            'transaction_amount' => 1,
            'credits' => 999999,
            'external_reference' => 'attacker-controlled',
            'payer' => ['email' => 'attacker@example.com'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'pending');
        $response->assertJsonPath('credits_credited', false);

        Http::assertSent(function ($request) use ($recharge) {
            return $request['payment_method_id'] === 'visa'
                && $request['installments'] === 3
                && $request['issuer_id'] === '310'
                && $request['token'] === 'card-brick-token-abc'
                && $request['transaction_amount'] === 30.0 // server-derivado, no el 1 del cliente
                && $request['external_reference'] === "recharge:{$recharge->id}:attempt:1"
                && ($request['payer']['email'] ?? null) !== 'attacker@example.com'
                && $request['payer']['identification']['type'] === 'DNI'
                && $request['payer']['identification']['number'] === '12345678';
        });
    }

    public function test_pay_accepts_a_card_instrument_without_optional_issuer_or_identification(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response([
            'id' => 778, 'status' => 'in_process', 'status_detail' => 'pending_contingency',
        ], 201)]);

        [$teacher, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.pay', $recharge), [
            'payment_method' => 'card',
            'token' => 'card-brick-token-min',
            'payment_method_id' => 'master',
            'installments' => 1,
        ])->assertOk();

        Http::assertSent(function ($request) {
            return $request['payment_method_id'] === 'master'
                && $request['installments'] === 1
                && ! isset($request['issuer_id'])
                && ! isset($request['payer']['identification']);
        });
    }

    public function test_pay_requires_card_metadata_when_payment_method_is_card(): void
    {
        [$teacher, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        // Falta payment_method_id/installments — nunca se completan con un
        // default server-side (ver sección 10 del encargo: "no
        // hardcodear issuer/installments/payment_method").
        $this->actingAs($teacher)
            ->postJson(route('teacher.credits.checkout.pay', $recharge), [
                'payment_method' => 'card',
                'token' => 'card-brick-token-incomplete',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_method_id', 'installments']);
    }

    public function test_pay_rejects_an_identification_type_outside_the_documented_whitelist(): void
    {
        [$teacher, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        $this->actingAs($teacher)
            ->postJson(route('teacher.credits.checkout.pay', $recharge), [
                'payment_method' => 'card',
                'token' => 'card-brick-token',
                'payment_method_id' => 'visa',
                'installments' => 1,
                'identification_type' => 'PASSPORT', // no está en DNI/C.E/RUC/Otro (Perú, ver MCP)
                'identification_number' => '12345678',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('identification_type');
    }

    public function test_pay_rejects_card_only_fields_when_payment_method_is_yape(): void
    {
        [$teacher, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        // Un intento 'yape' con payment_method_id/installments colados —
        // nunca se ignoran en silencio, 422 explícito (ver prohibited_if en pay()).
        $this->actingAs($teacher)
            ->postJson(route('teacher.credits.checkout.pay', $recharge), [
                'payment_method' => 'yape',
                'token' => 'yape-token',
                'payment_method_id' => 'visa',
                'installments' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_method_id', 'installments']);
    }

    /**
     * PCI (sección 4/10 del encargo): MOVA nunca acepta datos crudos de
     * tarjeta bajo ningún nombre de campo documentado, ni siquiera junto a
     * un token/payment_method_id por lo demás válidos.
     */
    public function test_pay_rejects_raw_card_data_even_alongside_a_valid_token(): void
    {
        // Un teacher/recharge NUEVO por campo — la ruta pay() tiene
        // throttle:10,1 (routes/web.php) y esta whitelist por sí sola ya
        // supera ese límite; reusar la misma sesión haría que las últimas
        // iteraciones fallaran por 429, no por la validación 422 que este
        // test en realidad quiere probar.
        foreach ([
            'card_number' => '4009175332806176',
            'cardNumber' => '4009175332806176',
            'cvv' => '123',
            'cvc' => '123',
            'security_code' => '123',
            'securityCode' => '123',
            'expiration_month' => '11',
            'expirationMonth' => '11',
            'expiration_year' => '30',
            'expirationYear' => '30',
            'expiration_date' => '11/30',
            'expirationDate' => '11/30',
        ] as $field => $value) {
            [$teacher, $profile] = $this->teacher();
            $recharge = $this->recharge($profile);

            $response = $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.pay', $recharge), [
                'payment_method' => 'card',
                'token' => 'card-brick-token',
                'payment_method_id' => 'visa',
                'installments' => 1,
                $field => $value,
            ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors($field);
        }
    }

    public function test_card_credits_are_applied_exactly_once_when_approved(): void
    {
        [$teacher, $profile] = $this->teacher(availableCredits: 0);
        $recharge = $this->recharge($profile, amountPen: '10.00', credits: 5);

        Http::fake([
            'api.mercadopago.com/v1/payments' => Http::response(['id' => 9101, 'status' => 'in_process', 'status_detail' => 'pending_contingency'], 201),
        ]);

        $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.pay', $recharge), [
            'payment_method' => 'card',
            'token' => 'card-brick-token',
            'payment_method_id' => 'visa',
            'installments' => 1,
        ])->assertOk();

        Http::fake([
            'api.mercadopago.com/v1/payments/9101' => Http::response([
                'id' => 9101, 'status' => 'approved', 'status_detail' => 'accredited',
                'transaction_amount' => 10.0, 'currency_id' => 'PEN',
                'external_reference' => "recharge:{$recharge->id}:attempt:1",
            ], 200),
        ]);

        $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.refresh', $recharge))->assertJsonPath('status', 'approved');
        $this->travel(10)->seconds();
        $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.refresh', $recharge))->assertJsonPath('status', 'approved');

        $profile->refresh();
        $this->assertSame(5, $profile->credits_available);
        $this->assertSame(1, \App\Models\CreditTransaction::where('recharge_request_id', $recharge->id)->where('type', 'deposit')->count());
    }

    public function test_card_payment_rejected_synchronously_credits_zero(): void
    {
        [$teacher, $profile] = $this->teacher(availableCredits: 0);
        $recharge = $this->recharge($profile, amountPen: '10.00', credits: 5);

        Http::fake([
            'api.mercadopago.com/v1/payments' => Http::response([
                'id' => 9102, 'status' => 'rejected', 'status_detail' => 'cc_rejected_other_reason',
            ], 201),
        ]);

        $response = $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.pay', $recharge), [
            'payment_method' => 'card',
            'token' => 'card-brick-token',
            'payment_method_id' => 'visa',
            'installments' => 1,
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'failed');
        $response->assertJsonPath('credits_credited', false);

        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertSame('failed', PaymentOrder::where('recharge_request_id', $recharge->id)->firstOrFail()->status);
        $this->assertDatabaseCount('credit_transactions', 0);
    }

    /**
     * pending/uncertain (Payments API "in_process"/red incierta) NUNCA
     * acredita — la única vía es la reconciliación server-to-server
     * (sección 7/11 del encargo, incluida la lectura de un futuro
     * status_detail=pending_challenge de 3DS: MercadoPagoPaymentStatusMapper
     * ya trata cualquier status='pending' igual, sin importar el detail).
     */
    public function test_card_payment_pending_credits_zero_until_reconciled(): void
    {
        [$teacher, $profile] = $this->teacher(availableCredits: 0);
        $recharge = $this->recharge($profile, amountPen: '10.00', credits: 5);

        Http::fake([
            'api.mercadopago.com/v1/payments' => Http::response([
                'id' => 9103, 'status' => 'pending', 'status_detail' => 'pending_contingency',
            ], 201),
        ]);

        $response = $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.pay', $recharge), [
            'payment_method' => 'card',
            'token' => 'card-brick-token',
            'payment_method_id' => 'visa',
            'installments' => 1,
        ]);

        $response->assertJsonPath('status', 'pending');
        $response->assertJsonPath('credits_credited', false);
        $this->assertSame(0, $profile->fresh()->credits_available);
    }

    /**
     * 3DS Challenge (MOVA Card Payment Brick 3DS): pay() debe exponer
     * action_required con exactamente lo que Checkout.vue necesita para
     * dibujar el iframe, y NUNCA acreditar ni marcar 'approved' solo porque
     * Mercado Pago devolvió pending_challenge en la respuesta síncrona.
     */
    public function test_card_payment_pending_challenge_exposes_action_required_without_crediting(): void
    {
        [$teacher, $profile] = $this->teacher(availableCredits: 0);
        $recharge = $this->recharge($profile, amountPen: '10.00', credits: 5);

        Http::fake([
            'api.mercadopago.com/v1/payments' => Http::response([
                'id' => 9106,
                'status' => 'pending',
                'status_detail' => 'pending_challenge',
                'three_ds_info' => [
                    'external_resource_url' => 'https://acs-public.tp.mastercard.com/api/v1/browser_Challenges',
                    'creq' => 'eyJmYWtlIjoiY3JlcSJ9',
                ],
            ], 201),
        ]);

        $response = $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.pay', $recharge), [
            'payment_method' => 'card',
            'token' => 'card-brick-token',
            'payment_method_id' => 'visa',
            'installments' => 1,
        ]);

        $response->assertJsonPath('status', 'pending');
        $response->assertJsonPath('credits_credited', false);
        $response->assertJsonPath('action_required.type', 'challenge');
        $response->assertJsonPath('action_required.payment_id', '9106');
        $response->assertJsonPath('action_required.external_resource_url', 'https://acs-public.tp.mastercard.com/api/v1/browser_Challenges');
        $response->assertJsonPath('action_required.creq', 'eyJmYWtlIjoiY3JlcSJ9');
        $this->assertSame(0, $profile->fresh()->credits_available);

        // status()/refresh() (GET/POST posteriores, ej. tras un reload de la
        // pantalla) deben seguir reportando exactamente el mismo Challenge —
        // nunca lo pierden ni lo inventan de nuevo.
        $statusResponse = $this->actingAs($teacher)->getJson(route('teacher.credits.checkout.status', $recharge));
        $statusResponse->assertJsonPath('action_required.type', 'challenge');
        $statusResponse->assertJsonPath('action_required.creq', 'eyJmYWtlIjoiY3JlcSJ9');

        // CACHE SAFETY (ronda de hardening final): un `creq` es un dato de un
        // solo Challenge — ningún caché compartido/de navegador debe
        // guardarlo.
        $response->assertHeader('Cache-Control', 'no-store, private');
        $statusResponse->assertHeader('Cache-Control', 'no-store, private');
    }

    /**
     * CHALLENGE TIMEOUT (ronda de hardening final): pasada la ventana de
     * ~5 minutos (PaymentOrder.three_ds_expires_at — columna DEDICADA,
     * nunca la `expires_at` genérica del PaymentOrder), el backend deja de
     * exponer action_required — nunca sigue ofreciendo un iframe que el ACS
     * del banco ya dejó de servir — pero el intento sigue 'pending' (nunca
     * se marca rechazado solo por esto) y credits_credited sigue false.
     */
    public function test_expired_challenge_stops_exposing_action_required_but_stays_pending(): void
    {
        [$teacher, $profile] = $this->teacher(availableCredits: 0);
        $recharge = $this->recharge($profile, amountPen: '10.00', credits: 5);

        PaymentOrder::create([
            'recharge_request_id' => $recharge->id,
            'attempt_number' => 1,
            'provider' => 'mercadopago',
            'provider_order_id' => '9200',
            'status' => 'pending',
            'submission_status' => 'submitted',
            'provider_status' => 'pending',
            'provider_status_detail' => 'pending_challenge',
            'three_ds_challenge_url' => 'https://acs-public.tp.mastercard.com/api/v1/browser_Challenges',
            'three_ds_creq' => 'eyJmYWtlIjoiY3JlcSJ9',
            'three_ds_expires_at' => now()->subMinute(), // ya vencido
            'amount_minor' => 1000,
            'currency' => 'PEN',
        ]);

        $response = $this->actingAs($teacher)->getJson(route('teacher.credits.checkout.status', $recharge));

        $response->assertJsonPath('status', 'pending');
        $response->assertJsonPath('action_required', null);
        $response->assertJsonPath('credits_credited', false);
        $this->assertSame(0, $profile->fresh()->credits_available);
    }

    /**
     * COMPENSATING CANCELLATION (LOST 3DS CHALLENGE, ronda de auditoría de
     * seguridad financiera final, segunda pasada, sección 3): la copia
     * "Estamos cerrando de forma segura..." se deriva de
     * isLostChallengeCompensationCandidate() — una lectura PURA de
     * status/submission_status/provider_status_detail/three_ds_(challenge)/
     * last_verified_at/created_at, NUNCA de `compensation_claimed_at` (ver
     * docblock de safeStatus()). Este test verifica explícitamente que eso
     * es seguro: con un claim FRESCO activo (el estado real que deja
     * claimForCompensation() mientras un worker está en medio de la
     * ronda — GET→PUT→GET), NADA de lo que esa condición sí consulta
     * cambia, así que la copia de compensación sigue mostrándose durante
     * TODA la ventana en la que la compensación está genuinamente en
     * curso — nunca desaparece a mitad de camino.
     */
    public function test_status_shows_the_compensating_message_and_hides_retry_while_a_fresh_claim_is_active(): void
    {
        [$teacher, $profile] = $this->teacher(availableCredits: 0);
        $recharge = $this->recharge($profile, amountPen: '10.00', credits: 5);

        $order = PaymentOrder::create([
            'recharge_request_id' => $recharge->id,
            'attempt_number' => 1,
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'provider' => 'mercadopago',
            'provider_order_id' => '9300',
            'status' => 'pending',
            'submission_status' => 'submitted',
            'provider_status' => 'pending',
            'provider_status_detail' => 'pending_challenge',
            'three_ds_challenge_url' => null, // Challenge perdido — nunca capturado
            'three_ds_creq' => null,
            'three_ds_expires_at' => null,
            'amount_minor' => 1000,
            'currency' => 'PEN',
        ]);

        // Presupuesto de recuperación agotado (mismo umbral que
        // isLostChallengeCompensationCandidate() exige, ver su docblock) +
        // ya verificado al menos una vez.
        $order->timestamps = false;
        $order->forceFill([
            'created_at' => now()->subMinutes(45),
            'updated_at' => now()->subMinutes(45),
            'last_verified_at' => now()->subMinutes(45),
        ])->save();

        // Claim FRESCO — exactamente el estado que deja claimForCompensation()
        // mientras un worker está a mitad de la ronda GET→PUT→GET.
        $order->update(['compensation_claimed_at' => now()]);

        $response = $this->actingAs($teacher)->getJson(route('teacher.credits.checkout.status', $recharge));

        $response->assertOk();
        $response->assertJsonPath('status', 'pending'); // nunca 'failed' — ningún reintento debe ofrecerse
        $response->assertJsonPath('credits_credited', false);
        $response->assertJsonPath('action_required', null);
        $response->assertJson(fn ($json) => $json
            ->where('message', 'Estamos cerrando de forma segura el intento anterior. No vuelvas a pagar todavía.')
            ->etc());
        $this->assertSame(0, $profile->fresh()->credits_available);

        // GET STATUS SIDE EFFECT: lectura pura, nunca reconcilia (isLostChallengeCompensationCandidate() no hace I/O).
        $claimedAt = $order->fresh()->compensation_claimed_at;
        Http::fake();
        $this->actingAs($teacher)->getJson(route('teacher.credits.checkout.status', $recharge));
        Http::assertNothingSent();
        $this->assertEquals($claimedAt, $order->fresh()->compensation_claimed_at, 'status() nunca toca el claim');
    }

    public function test_card_payment_without_challenge_never_exposes_action_required(): void
    {
        [$teacher, $profile] = $this->teacher(availableCredits: 0);
        $recharge = $this->recharge($profile, amountPen: '10.00', credits: 5);

        Http::fake([
            'api.mercadopago.com/v1/payments' => Http::response([
                'id' => 9107, 'status' => 'pending', 'status_detail' => 'pending_contingency',
            ], 201),
        ]);

        $response = $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.pay', $recharge), [
            'payment_method' => 'card',
            'token' => 'card-brick-token',
            'payment_method_id' => 'visa',
            'installments' => 1,
        ]);

        $response->assertJsonPath('action_required', null);
    }

    /**
     * Regresión de RESOLVE ATTEMPT ROW (MercadoPagoPaymentProvider): tras
     * un rechazo TERMINAL (status='failed'), un reintento del profesor —
     * ahora con OTRO token/tarjeta — debe crear un intento NUEVO
     * (attempt_number+1) con una idempotency_key NUEVA, nunca reutilizar la
     * fila fallida ni su key.
     */
    public function test_retry_after_a_terminal_card_rejection_creates_a_legitimate_new_attempt(): void
    {
        [$teacher, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        Http::fake([
            'api.mercadopago.com/v1/payments' => Http::response([
                'id' => 9104, 'status' => 'rejected', 'status_detail' => 'cc_rejected_bad_filled_security_code',
            ], 201),
        ]);

        $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.pay', $recharge), [
            'payment_method' => 'card',
            'token' => 'card-brick-token-first',
            'payment_method_id' => 'visa',
            'installments' => 1,
        ])->assertOk();

        $firstAttempt = PaymentOrder::where('recharge_request_id', $recharge->id)->firstOrFail();
        $this->assertSame(1, $firstAttempt->attempt_number);
        $this->assertSame('failed', $firstAttempt->status);

        Http::fake([
            'api.mercadopago.com/v1/payments' => Http::response([
                'id' => 9105, 'status' => 'in_process', 'status_detail' => 'pending_contingency',
            ], 201),
        ]);

        $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.pay', $recharge), [
            'payment_method' => 'card',
            'token' => 'card-brick-token-second',
            'payment_method_id' => 'master',
            'installments' => 1,
        ])->assertOk();

        $this->assertSame(2, PaymentOrder::where('recharge_request_id', $recharge->id)->count());
        $secondAttempt = PaymentOrder::where('recharge_request_id', $recharge->id)->where('attempt_number', 2)->firstOrFail();
        $this->assertNotSame($firstAttempt->idempotency_key, $secondAttempt->idempotency_key);
        $this->assertSame('pending', $secondAttempt->status);
    }

    public function test_repeated_refresh_never_duplicates_credits_for_a_card_payment(): void
    {
        [$teacher, $profile] = $this->teacher(availableCredits: 0);
        $recharge = $this->recharge($profile, amountPen: '10.00', credits: 5);

        Http::fake([
            'api.mercadopago.com/v1/payments' => Http::response(['id' => 9106, 'status' => 'in_process', 'status_detail' => 'pending_contingency'], 201),
        ]);
        $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.pay', $recharge), [
            'payment_method' => 'card',
            'token' => 'card-brick-token',
            'payment_method_id' => 'visa',
            'installments' => 1,
        ])->assertOk();

        Http::fake([
            'api.mercadopago.com/v1/payments/9106' => Http::response([
                'id' => 9106, 'status' => 'approved', 'status_detail' => 'accredited',
                'transaction_amount' => 10.0, 'currency_id' => 'PEN',
                'external_reference' => "recharge:{$recharge->id}:attempt:1",
            ], 200),
        ]);

        $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.refresh', $recharge))->assertJsonPath('status', 'approved');
        $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.refresh', $recharge))->assertJsonPath('status', 'approved');

        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertSame(1, \App\Models\CreditTransaction::where('recharge_request_id', $recharge->id)->where('type', 'deposit')->count());
    }

    // ---- pending/uncertain UX contract -------------------------------------

    public function test_status_never_reports_failed_for_a_pending_or_uncertain_attempt(): void
    {
        [$teacher, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);
        $order = PaymentOrder::create([
            'recharge_request_id' => $recharge->id,
            'attempt_number' => 1,
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'provider' => 'mercadopago',
            'provider_order_id' => null,
            'submission_status' => 'uncertain',
            'status' => 'pending',
            'amount_minor' => 1000,
            'currency' => 'PEN',
        ]);

        Http::fake(['api.mercadopago.com/*' => Http::response(['results' => []], 200)]);

        $response = $this->actingAs($teacher)->getJson(route('teacher.credits.checkout.status', $recharge));

        $response->assertOk();
        $response->assertJsonPath('status', 'uncertain');
        $response->assertJson(fn ($json) => $json
            ->where('credits_credited', false)
            ->where('message', 'Estamos verificando tu pago. No vuelvas a pagar mientras termina la verificación.')
            ->etc());
        $this->assertStringNotContainsStringIgnoringCase('fall', $response->json('message'));
    }

    // ---- aprobado exactamente una vez, incluso con polling repetido -------

    public function test_credits_are_applied_exactly_once_even_when_refresh_is_polled_repeatedly(): void
    {
        [$teacher, $profile] = $this->teacher(availableCredits: 0);
        $recharge = $this->recharge($profile, amountPen: '10.00', credits: 5);

        Http::fake([
            'api.mercadopago.com/v1/payments' => Http::response(['id' => 9001, 'status' => 'in_process', 'status_detail' => 'pending_contingency'], 201),
        ]);

        $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.pay', $recharge), ['payment_method' => 'yape', 'token' => 'tok'])->assertOk();

        // Ahora Mercado Pago ya confirmó approved/accredited server-to-server.
        Http::fake([
            'api.mercadopago.com/v1/payments/9001' => Http::response([
                'id' => 9001, 'status' => 'approved', 'status_detail' => 'accredited',
                'transaction_amount' => 10.0, 'currency_id' => 'PEN',
                'external_reference' => "recharge:{$recharge->id}:attempt:1",
            ], 200),
        ]);

        // Tres polls seguidos del profesor (refresh(), no status() — ver
        // STATUS SEMANTICS) — debe acreditar UNA sola vez.
        $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.refresh', $recharge))->assertJsonPath('status', 'approved');
        $this->travel(10)->seconds(); // supera el debounce de polling
        $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.refresh', $recharge))->assertJsonPath('status', 'approved');
        $this->travel(10)->seconds();
        $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.refresh', $recharge))->assertJsonPath('status', 'approved');

        $profile->refresh();
        $this->assertSame(5, $profile->credits_available);
        $this->assertSame(1, \App\Models\CreditTransaction::where('recharge_request_id', $recharge->id)->where('type', 'deposit')->count());
    }

    /**
     * STATUS SEMANTICS (MOVA Yape Final Pre-Card Gate): status() (GET) es
     * lectura pura — nunca dispara la llamada server-to-server a Mercado
     * Pago ni puede acreditar créditos, sin importar cuántas veces se
     * repita, incluso cuando la verdad del proveedor (si se consultara)
     * diría 'approved'. Reemplaza al test anterior que documentaba el
     * side-effect en GET como deuda temporal — esa deuda ya está resuelta:
     * la reconciliación vive exclusivamente en refresh() (ver el siguiente
     * test).
     */
    public function test_get_status_never_reconciles_or_mutates_state(): void
    {
        [$teacher, $profile] = $this->teacher(availableCredits: 0);
        $recharge = $this->recharge($profile, amountPen: '10.00', credits: 5);

        Http::fake([
            'api.mercadopago.com/v1/payments' => Http::response(['id' => 9002, 'status' => 'in_process', 'status_detail' => 'pending_contingency'], 201),
        ]);
        $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.pay', $recharge), ['payment_method' => 'yape', 'token' => 'tok'])->assertOk();

        Http::fake([
            'api.mercadopago.com/v1/payments/9002' => Http::response([
                'id' => 9002, 'status' => 'approved', 'status_detail' => 'accredited',
                'transaction_amount' => 10.0, 'currency_id' => 'PEN',
                'external_reference' => "recharge:{$recharge->id}:attempt:1",
            ], 200),
        ]);

        $this->actingAs($teacher)->getJson(route('teacher.credits.checkout.status', $recharge))->assertJsonPath('status', 'pending');
        $this->actingAs($teacher)->getJson(route('teacher.credits.checkout.status', $recharge))->assertJsonPath('status', 'pending');

        Http::assertNotSent(fn ($request) => $request->url() === 'https://api.mercadopago.com/v1/payments/9002');
        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertDatabaseCount('credit_transactions', 0);
        $this->assertSame('pending', PaymentOrder::where('recharge_request_id', $recharge->id)->firstOrFail()->status);

        // refresh() (POST) SÍ reconcilia — confirma que el mismo escenario
        // que status() dejó intacto se resuelve por el endpoint correcto.
        $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.refresh', $recharge))->assertJsonPath('status', 'approved');
        $this->assertSame(5, $profile->fresh()->credits_available);
    }

    /**
     * Antes esta protección vivía implícitamente en el hecho de que GET
     * status() reconciliaba — ahora que refresh() (POST) es el único
     * camino, debe seguir siendo seguro repetirlo (polling real, dos
     * "simultáneos", etc.): exactamente un abono, nunca ninguno perdido ni
     * duplicado.
     */
    public function test_repeated_refresh_never_duplicates_credits(): void
    {
        [$teacher, $profile] = $this->teacher(availableCredits: 0);
        $recharge = $this->recharge($profile, amountPen: '10.00', credits: 5);

        Http::fake([
            'api.mercadopago.com/v1/payments' => Http::response(['id' => 9003, 'status' => 'in_process', 'status_detail' => 'pending_contingency'], 201),
        ]);
        $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.pay', $recharge), ['payment_method' => 'yape', 'token' => 'tok'])->assertOk();

        Http::fake([
            'api.mercadopago.com/v1/payments/9003' => Http::response([
                'id' => 9003, 'status' => 'approved', 'status_detail' => 'accredited',
                'transaction_amount' => 10.0, 'currency_id' => 'PEN',
                'external_reference' => "recharge:{$recharge->id}:attempt:1",
            ], 200),
        ]);

        $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.refresh', $recharge))->assertJsonPath('status', 'approved');
        $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.refresh', $recharge))->assertJsonPath('status', 'approved');

        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertSame(1, \App\Models\CreditTransaction::where('recharge_request_id', $recharge->id)->where('type', 'deposit')->count());
    }

    // ---- helpers ---------------------------------------------------------

    private function teacher(int $availableCredits = 0): array
    {
        $teacher = User::factory()->create(['password' => 'password']);
        $teacher->assignRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_verified' => true,
            'credits_available' => $availableCredits,
            'credits_reserved' => 0,
        ]);

        return [$teacher, $profile];
    }

    private function recharge(TeacherProfile $profile, string $amountPen = '10.00', int $credits = 5): RechargeRequest
    {
        $operation = fake()->unique()->numerify('MPTEST########');

        return RechargeRequest::create([
            'teacher_profile_id' => $profile->id,
            'package_code' => 'inicio',
            'package_name' => 'Inicio',
            'credits' => $credits,
            'amount_pen' => $amountPen,
            'payment_method' => 'mercadopago',
            'operation_number' => $operation,
            'operation_number_normalized' => $operation,
            'status' => 'pending',
        ]);
    }
}
