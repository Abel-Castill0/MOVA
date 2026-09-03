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

        $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => 'tok'])->assertOk();

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
        $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => 'tok'])->assertOk();

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
        $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => 'tok'])->assertOk();

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
