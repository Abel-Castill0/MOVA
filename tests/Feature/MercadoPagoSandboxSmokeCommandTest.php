<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\PaymentOrder;
use App\Models\RechargeRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Mecánica de `mercadopago:sandbox-smoke` con HTTP SIMULADO.
 *
 * IMPORTANTE: estos tests NO demuestran que Mercado Pago funcione — demuestran
 * que la sonda (a) se niega a correr en condiciones inseguras, (b) encadena
 * correctamente tokenización → pago → conciliación → webhook firmado → ledger y
 * (c) verifica "exactamente un depósito". La prueba real contra el sandbox se
 * hace con `bash scripts/sandbox-smoke.sh payments` y credenciales de prueba.
 */
class MercadoPagoSandboxSmokeCommandTest extends TestCase
{
    use RefreshDatabase;

    private const COLLECTOR = '123456789';

    private const SECRET = 'whsec-test-only-secret';

    /** @var array{status:string, ref:?string, amount:mixed} */
    private array $state = ['status' => 'approved', 'ref' => null, 'amount' => null];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payments.enabled' => true,
            'payments.provider' => 'mercadopago',
            'payments.mercadopago.base_url' => 'https://api.mercadopago.com',
            'payments.mercadopago.access_token' => 'TEST-access-token-for-tests',
            'payments.mercadopago.public_key' => 'TEST-public-key-for-tests',
            'payments.mercadopago.application_id' => '6583217782927097',
            'payments.mercadopago.expected_collector_id' => self::COLLECTOR,
            'payments.mercadopago.expected_live_mode' => 'false',
            'payments.mercadopago.webhooks_enabled' => true,
            'payments.mercadopago.webhook_secret' => self::SECRET,
            'queue.default' => 'sync',
        ]);
    }

    private function paymentJson(bool $live = false): array
    {
        $detail = match ($this->state['status']) {
            'approved' => 'accredited',
            'rejected' => 'cc_rejected_other_reason',
            default => 'pending_contingency',
        };

        return [
            'id' => 9000000001,
            'status' => $this->state['status'],
            'status_detail' => $detail,
            'transaction_amount' => $this->state['amount'],
            'currency_id' => 'PEN',
            'external_reference' => $this->state['ref'],
            'collector_id' => (int) self::COLLECTOR,
            'payment_method_id' => 'master',
            'payment_type_id' => 'credit_card',
            'live_mode' => $live,
        ];
    }

    private function fakeMercadoPago(bool $live = false): void
    {
        Http::fake(function (Request $request) use ($live) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);

            if (str_contains($path, '/v1/payment_methods')) {
                return Http::response([['id' => 'master', 'name' => 'Mastercard', 'payment_type_id' => 'credit_card', 'status' => 'active']], 200);
            }

            if (str_contains($path, '/v1/card_tokens')) {
                $holder = (string) ($request['cardholder']['name'] ?? '');
                $this->state['status'] = match ($holder) {
                    'APRO' => 'approved',
                    'OTHE' => 'rejected',
                    default => 'in_process',
                };

                return Http::response(['id' => 'tok_'.strtolower($holder)], 201);
            }

            if ($request->method() === 'POST' && str_ends_with($path, '/v1/payments')) {
                $this->state['ref'] = $request['external_reference'];
                $this->state['amount'] = $request['transaction_amount'];

                return Http::response($this->paymentJson($live), 201);
            }

            if ($request->method() === 'GET' && preg_match('#/v1/payments/\d+$#', $path)) {
                return Http::response($this->paymentJson($live), 200);
            }

            return Http::response([], 404);
        });
    }

    // ── Condiciones inseguras: se niega ANTES de cualquier llamada ───────

    public function test_it_refuses_to_run_outside_local_and_testing(): void
    {
        Http::fake();
        $this->app['env'] = 'production';

        $this->artisan('mercadopago:sandbox-smoke')->assertFailed();

        Http::assertNothingSent();
        $this->assertSame(0, PaymentOrder::count());
    }

    public function test_it_refuses_unless_expected_live_mode_is_explicitly_false(): void
    {
        Http::fake();

        foreach ([null, '', 'true', '1'] as $value) {
            config(['payments.mercadopago.expected_live_mode' => $value]);
            $this->artisan('mercadopago:sandbox-smoke')->assertFailed();
        }

        Http::assertNothingSent();
    }

    public function test_the_boolean_false_that_env_produces_passes_the_live_mode_guard(): void
    {
        Http::fake();
        // env('MERCADOPAGO_EXPECTED_LIVE_MODE') con valor "false" llega como booleano false.
        config(['payments.mercadopago.expected_live_mode' => false]);

        \Illuminate\Support\Facades\Artisan::call('mercadopago:sandbox-smoke', ['--skip-webhook' => true]);

        $this->assertStringNotContainsString('MERCADOPAGO_EXPECTED_LIVE_MODE debe ser', \Illuminate\Support\Facades\Artisan::output());
    }

    public function test_it_refuses_without_test_credentials(): void
    {
        Http::fake();

        config(['payments.mercadopago.access_token' => null]);
        $this->artisan('mercadopago:sandbox-smoke')->assertFailed();

        config(['payments.mercadopago.access_token' => 'TEST-x', 'payments.mercadopago.public_key' => null]);
        $this->artisan('mercadopago:sandbox-smoke')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_it_refuses_a_non_qa_database_connection(): void
    {
        Http::fake();
        $original = config('database.default');

        try {
            config(['database.default' => 'mysql']);
            $this->artisan('mercadopago:sandbox-smoke')->assertFailed();
        } finally {
            // Sin restaurarla, el rollback de RefreshDatabase iría a otra conexión.
            config(['database.default' => $original]);
        }

        Http::assertNothingSent();
    }

    public function test_it_stops_if_the_pre_flight_authentication_fails(): void
    {
        Http::fake(['api.mercadopago.com/*' => Http::response(['message' => 'unauthorized'], 401)]);

        $this->artisan('mercadopago:sandbox-smoke')->assertFailed();

        $this->assertSame(0, PaymentOrder::count());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/v1/card_tokens'));
    }

    // ── Camino feliz y rechazo, con el ledger como árbitro ───────────────

    public function test_approved_credits_exactly_once_even_with_replayed_reconciliation_and_webhook(): void
    {
        $this->fakeMercadoPago();

        // "200 / 200": el endpoint real aceptó la notificación firmada y su replay.
        $this->artisan('mercadopago:sandbox-smoke', ['--scenario' => ['approved']])
            ->expectsOutputToContain('200 / 200')
            ->assertSuccessful();

        $order = PaymentOrder::firstOrFail();
        $recharge = RechargeRequest::firstOrFail();
        $profile = $recharge->teacherProfile;

        $this->assertSame(1, CreditTransaction::where('teacher_profile_id', $profile->id)->where('type', 'deposit')->count());
        $this->assertSame((int) $recharge->credits, (int) $profile->fresh()->credits_available);
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_rejected_never_credits(): void
    {
        $this->fakeMercadoPago();

        $this->artisan('mercadopago:sandbox-smoke', ['--scenario' => ['rejected']])->assertSuccessful();

        $recharge = RechargeRequest::firstOrFail();
        $this->assertSame(0, CreditTransaction::where('type', 'deposit')->count());
        $this->assertSame(0, (int) $recharge->teacherProfile->fresh()->credits_available);
        $this->assertNotSame('paid', PaymentOrder::firstOrFail()->status);
    }

    public function test_pending_stays_pending_and_never_credits(): void
    {
        $this->fakeMercadoPago();

        $this->artisan('mercadopago:sandbox-smoke', ['--scenario' => ['pending']])->assertSuccessful();

        $this->assertSame(0, CreditTransaction::where('type', 'deposit')->count());
        $this->assertNotSame('paid', PaymentOrder::firstOrFail()->status);
    }

    public function test_default_scenarios_run_approved_then_rejected_and_each_gets_its_own_teacher(): void
    {
        $this->fakeMercadoPago();

        $this->artisan('mercadopago:sandbox-smoke')->assertSuccessful();

        $this->assertSame(2, RechargeRequest::count());
        $this->assertSame(1, CreditTransaction::where('type', 'deposit')->count());
    }

    // ── Seguridad: la propia respuesta de Mercado Pago manda ────────────

    public function test_it_aborts_if_mercado_pago_reports_live_mode_true(): void
    {
        $this->fakeMercadoPago(live: true);

        $this->artisan('mercadopago:sandbox-smoke', ['--scenario' => ['approved']])->assertFailed();

        // Aunque el pago (simulado) estuviera aprobado, la sonda no sigue adelante.
        $this->assertSame(0, CreditTransaction::where('type', 'deposit')->count());
    }

    public function test_the_signed_webhook_phase_is_skipped_without_a_secret_and_says_so(): void
    {
        $this->fakeMercadoPago();
        config(['payments.mercadopago.webhook_secret' => null]);

        $this->artisan('mercadopago:sandbox-smoke', ['--scenario' => ['approved']])
            ->expectsOutputToContain('OMITIDO')
            ->assertSuccessful();
    }

    public function test_the_command_never_prints_credentials(): void
    {
        $this->fakeMercadoPago();

        $this->artisan('mercadopago:sandbox-smoke', ['--scenario' => ['approved']])
            ->doesntExpectOutputToContain('TEST-access-token-for-tests')
            ->doesntExpectOutputToContain('TEST-public-key-for-tests')
            ->doesntExpectOutputToContain(self::SECRET)
            ->assertSuccessful();
    }
}
