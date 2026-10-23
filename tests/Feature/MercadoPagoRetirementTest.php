<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\PaymentOrder;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Payment\Contracts\YapePaymentInstrument;
use App\Payment\MercadoPagoPaymentProvider;
use App\Support\CheckoutProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Mercado Pago está RETIRADO para compras nuevas (MERCADOPAGO_NEW_PAYMENTS_ENABLED=false, el valor por defecto de
 * producción): nada puede abrir checkout ni crear RechargeRequest/PaymentOrder nuevos por ese proveedor, no hay
 * respaldo automático hacia Mercado Pago ni hacia fake cuando Culqi no está listo, y las operaciones históricas siguen
 * verificándose y conciliándose.
 */
class MercadoPagoRetirementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payments.enabled' => true,
            'payments.provider' => 'mercadopago',
            'payments.mercadopago.new_payments_enabled' => false, // producción: retirado
            'payments.mercadopago.base_url' => 'https://api.mercadopago.com',
            'payments.mercadopago.access_token' => 'retirement-test-placeholder-000',
            'payments.mercadopago.public_key' => 'TEST-retirement-public-key',
            'payments.mercadopago.application_id' => '6583217782927097',
            'payments.mercadopago.expected_collector_id' => '123456789',
            'payments.mercadopago.expected_live_mode' => 'false',
            'payments.mercadopago.webhooks_enabled' => true,
            'payments.mercadopago.webhook_secret' => 'retirement-test-webhook-secret-0000000000',
        ]);
    }

    // ── Compras nuevas: bloqueadas ───────────────────────────────────────

    public function test_no_provider_offers_new_purchases_when_only_mercadopago_is_configured(): void
    {
        [$teacher] = $this->teacher();

        $this->assertNull(CheckoutProvider::active($teacher));
        $this->assertFalse(CheckoutProvider::mercadoPagoEnabled($teacher));

        $this->actingAs($teacher)->get(route('teacher.credits.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('checkoutProvider', null)
                ->where('mercadoPagoCheckoutEnabled', false));
    }

    public function test_store_creates_nothing_and_answers_503_so_no_new_mercadopago_recharge_exists(): void
    {
        [$teacher] = $this->teacher();
        Http::fake();

        $this->actingAs($teacher)->post(route('teacher.credits.checkout.store'), ['package_code' => 'inicio'])->assertStatus(503);

        $this->assertSame(0, RechargeRequest::count());
        $this->assertSame(0, PaymentOrder::count());
        Http::assertNothingSent();
    }

    public function test_an_old_mercadopago_recharge_cannot_open_the_payment_screen(): void
    {
        [$teacher, $profile] = $this->teacher();
        $recharge = $this->mercadoPagoRecharge($profile);

        $this->actingAs($teacher)->get(route('teacher.credits.checkout.show', $recharge))
            ->assertRedirect(route('teacher.credits.index'))
            ->assertSessionHas('error');
    }

    public function test_pay_on_an_old_mercadopago_recharge_is_gone_and_never_reaches_the_provider(): void
    {
        [$teacher, $profile] = $this->teacher();
        $recharge = $this->mercadoPagoRecharge($profile);
        Http::fake();

        $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.pay', $recharge), ['payment_method' => 'yape', 'token' => 'tok'])
            ->assertStatus(410);

        $this->assertSame(0, PaymentOrder::count());
        Http::assertNothingSent();
    }

    public function test_the_provider_itself_refuses_to_create_a_new_payment_from_any_entry_point(): void
    {
        [, $profile] = $this->teacher();
        $recharge = $this->mercadoPagoRecharge($profile);
        Http::fake();

        try {
            app(MercadoPagoPaymentProvider::class)->createPaymentAttempt($recharge, new YapePaymentInstrument('tok'));
            $this->fail('El proveedor debía negarse a crear un pago nuevo.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ya no admite pagos nuevos', $e->getMessage());
        }

        $this->assertSame(0, PaymentOrder::count());
        Http::assertNothingSent();
    }

    // ── Sin respaldo automático ──────────────────────────────────────────

    public function test_a_culqi_misconfiguration_never_falls_back_to_mercadopago_even_with_the_emergency_switch(): void
    {
        [$teacher] = $this->teacher();
        config([
            'payments.provider' => 'culqi',
            'payments.mercadopago.new_payments_enabled' => true, // aun con la marcha atrás encendida
            'payments.culqi.public_key' => null,
            'payments.culqi.private_key' => null,
        ]);

        $this->assertNull(CheckoutProvider::active($teacher));
        $this->actingAs($teacher)->post(route('teacher.credits.checkout.store'), ['package_code' => 'inicio'])->assertStatus(503);
        $this->assertSame(0, RechargeRequest::count());
    }

    public function test_culqi_is_the_only_provider_offered_when_it_is_ready(): void
    {
        [$teacher] = $this->teacher();
        config([
            'payments.provider' => 'culqi',
            'payments.culqi.env' => 'test',
            'payments.culqi.public_key' => 'pk_test_AbCdEf0123456789',
            'payments.culqi.private_key' => 'sk_test_ZyXwVu9876543210',
            'payments.culqi.webhooks_enabled' => false,
            'payments.culqi.encrypt_payloads' => false,
        ]);

        $this->assertSame('culqi', CheckoutProvider::active($teacher));

        $this->actingAs($teacher)->post(route('teacher.credits.checkout.store'), ['package_code' => 'inicio']);

        $recharge = RechargeRequest::firstOrFail();
        $this->assertSame('culqi', $recharge->payment_method);
    }

    // ── Operaciones históricas: se conservan ─────────────────────────────

    public function test_an_historical_mercadopago_payment_is_still_verified_and_credited_exactly_once_after_the_switch_to_culqi(): void
    {
        [$teacher, $profile] = $this->teacher();
        $recharge = $this->mercadoPagoRecharge($profile);

        // Estado previo realista: el cobro se creó antes del retiro (bandera encendida) y quedó en proceso.
        config(['payments.mercadopago.new_payments_enabled' => true]);
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response(['id' => 9101, 'status' => 'in_process', 'status_detail' => 'pending_contingency'], 201)]);
        $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.pay', $recharge), ['payment_method' => 'yape', 'token' => 'tok'])->assertOk();

        // Retiro + Culqi como proveedor activo (sin llaves: ni siquiera está listo). Lo histórico debe seguir funcionando.
        config(['payments.mercadopago.new_payments_enabled' => false, 'payments.provider' => 'culqi']);
        Http::fake([
            'api.mercadopago.com/v1/payments/9101' => Http::response([
                'id' => 9101, 'status' => 'approved', 'status_detail' => 'accredited',
                'transaction_amount' => 10.0, 'currency_id' => 'PEN',
                'external_reference' => "recharge:{$recharge->id}:attempt:1",
            ], 200),
        ]);

        $this->assertTrue(CheckoutProvider::mercadoPagoLegacyProcessing());
        $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.refresh', $recharge))->assertJsonPath('status', 'approved');
        $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.refresh', $recharge))->assertJsonPath('status', 'approved');

        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::where('recharge_request_id', $recharge->id)->where('type', 'deposit')->count());
        $this->assertSame('mercadopago', $recharge->fresh()->payment_method);
    }

    public function test_the_mercadopago_reconcile_sweep_stays_scheduled_while_the_provider_is_culqi_and_the_webhook_is_enabled(): void
    {
        config(['payments.provider' => 'culqi']);
        $this->assertStringContainsString('mercadopago:reconcile', implode(' | ', $this->scheduledCommands()));

        config(['payments.mercadopago.webhooks_enabled' => false]);
        $this->assertStringNotContainsString('mercadopago:reconcile', implode(' | ', $this->scheduledCommands()));

        config(['payments.enabled' => false, 'payments.mercadopago.webhooks_enabled' => true]);
        $this->assertStringNotContainsString('mercadopago:reconcile', implode(' | ', $this->scheduledCommands()));
    }

    public function test_the_culqi_reconcile_sweep_survives_a_provider_rollback_so_pending_culqi_payments_still_close(): void
    {
        config([
            'payments.provider' => 'mercadopago', // rollback: compras cerradas con Mercado Pago retirado
            'payments.culqi.env' => 'test',
            'payments.culqi.private_key' => 'sk_test_ZyXwVu9876543210',
            'payments.culqi.webhooks_enabled' => true,
        ]);
        $this->assertStringContainsString('culqi:reconcile', implode(' | ', $this->scheduledCommands()));

        config(['payments.culqi.webhooks_enabled' => false]);
        $this->assertStringNotContainsString('culqi:reconcile', implode(' | ', $this->scheduledCommands()));

        config(['payments.culqi.webhooks_enabled' => true, 'payments.culqi.private_key' => null]);
        $this->assertStringNotContainsString('culqi:reconcile', implode(' | ', $this->scheduledCommands()));
    }

    public function test_the_mercadopago_webhook_endpoint_is_independent_of_the_active_provider(): void
    {
        config(['payments.provider' => 'culqi']);

        // Sin firma válida responde 401: llega al verificador de Mercado Pago, no a un 404 de "proveedor distinto".
        $this->postJson('/api/webhooks/mercadopago', ['type' => 'payment', 'data' => ['id' => '1']])->assertStatus(401);
    }

    public function test_an_in_flight_historical_attempt_still_shows_its_state_read_only_and_a_finished_one_redirects(): void
    {
        [$teacher, $profile] = $this->teacher();
        $recharge = $this->mercadoPagoRecharge($profile);
        PaymentOrder::create([
            'recharge_request_id' => $recharge->id, 'attempt_number' => 1, 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'provider' => 'mercadopago', 'provider_order_id' => '9301', 'submission_status' => 'submitted', 'status' => 'pending',
            'amount_minor' => 1000, 'currency' => 'PEN',
        ]);

        $this->actingAs($teacher)->get(route('teacher.credits.checkout.show', $recharge))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Teacher/Credits/Checkout')
                ->where('checkoutEnabled', false)
                ->where('mercadoPagoPublicKey', null));

        PaymentOrder::where('recharge_request_id', $recharge->id)->update(['status' => 'failed']);
        $this->actingAs($teacher)->get(route('teacher.credits.checkout.show', $recharge->fresh()))
            ->assertRedirect(route('teacher.credits.index'));
    }

    public function test_with_culqi_as_provider_the_historical_mercadopago_settings_are_required_not_silently_skipped(): void
    {
        config([
            'payments.provider' => 'culqi',
            'payments.culqi.env' => 'test',
            'payments.culqi.public_key' => 'pk_test_AbCdEf0123456789',
            'payments.culqi.private_key' => 'sk_test_ZyXwVu9876543210',
            'payments.culqi.webhooks_enabled' => false,
            'payments.culqi.encrypt_payloads' => false,
            'payments.mercadopago.webhooks_enabled' => true,
            'payments.mercadopago.expected_collector_id' => null, // faltaría el control de cuenta vendedora
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/MERCADOPAGO_EXPECTED_COLLECTOR_ID/');

        app(\App\Payment\Contracts\PaymentProviderContract::class);
    }

    public function test_culqi_legacy_processing_matrix(): void
    {
        config(['payments.culqi.env' => 'test', 'payments.culqi.private_key' => 'sk_test_ZyXwVu9876543210', 'payments.culqi.webhooks_enabled' => false, 'payments.provider' => 'culqi']);
        $this->assertTrue(CheckoutProvider::culqiLegacyProcessing());

        config(['payments.provider' => 'mercadopago']);
        $this->assertFalse(CheckoutProvider::culqiLegacyProcessing());

        config(['payments.culqi.webhooks_enabled' => true]);
        $this->assertTrue(CheckoutProvider::culqiLegacyProcessing());

        config(['payments.culqi.private_key' => null]);
        $this->assertFalse(CheckoutProvider::culqiLegacyProcessing());

        // Con Culqi como proveedor se concilia siempre (el guard de arranque ya exige sus llaves).
        config(['payments.provider' => 'culqi']);
        $this->assertTrue(CheckoutProvider::culqiLegacyProcessing());
    }
    // ── helpers ──────────────────────────────────────────────────────────

    private function teacher(): array
    {
        $teacher = User::factory()->create(['password' => 'password']);
        $teacher->assignRole('teacher');
        $profile = TeacherProfile::create(['user_id' => $teacher->id, 'is_verified' => true, 'credits_available' => 0, 'credits_reserved' => 0]);

        return [$teacher, $profile];
    }

    private function mercadoPagoRecharge(TeacherProfile $profile): RechargeRequest
    {
        return RechargeRequest::create([
            'teacher_profile_id' => $profile->id, 'package_code' => 'inicio', 'package_name' => 'Inicio', 'credits' => 5,
            'amount_pen' => '10.00', 'payment_method' => 'mercadopago', 'operation_number' => null, 'operation_number_normalized' => null, 'status' => 'pending',
        ]);
    }

    /** @return list<string> */
    private function scheduledCommands(): array
    {
        // Se reconstruye el Schedule desde cero para que lea la config actual del test.
        $schedule = new Schedule();
        (new \ReflectionMethod(\App\Console\Kernel::class, 'schedule'))
            ->invoke($this->app->make(\App\Console\Kernel::class), $schedule);

        return array_map(fn ($event) => (string) ($event->command ?? ''), $schedule->events());
    }
}
