<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\PaymentOrder;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * culqi:activation-check — la puerta automática del script de activación en Azure. Solo lectura: nunca crea cargos ni
 * imprime llaves, secretos o correos; distingue PREPARADO / ACTIVO-PRUEBA / ACTIVO-GENERAL y solo marca la compra live
 * como verificada si hay un cargo de Culqi pagado CON su depósito único en el ledger.
 */
class CulqiActivationCheckTest extends TestCase
{
    use RefreshDatabase;

    private const PK = 'pk_live_AbCdEf0123456789';
    private const SK = 'sk_live_ZyXwVu9876543210';
    private const HOOK = 'w3bh00k-s3cr3t-0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('teacher', 'web');
        $this->app['env'] = 'production'; // las llaves live solo son coherentes en producción
        config([
            'payments.enabled' => true,
            'payments.provider' => 'mercadopago',
            'payments.mercadopago.new_payments_enabled' => false,
            'payments.culqi.env' => 'live',
            'payments.culqi.public_key' => self::PK,
            'payments.culqi.private_key' => self::SK,
            'payments.culqi.webhook_secret' => self::HOOK,
            'payments.culqi.webhooks_enabled' => true,
            'payments.culqi.methods' => 'card',
            'payments.culqi.checkout_allowlist' => '',
            'payments.culqi.base_url' => 'https://api.culqi.com',
            'payments.culqi.secure_url' => 'https://secure.culqi.com',
            'credits.verification_package.enabled' => false,
        ]);
    }

    private function fakeCulqiOk(): void
    {
        Http::fake(['https://api.culqi.com/*' => Http::response(['data' => []], 200)]);
    }

    private function check(array $options = []): array
    {
        $code = Artisan::call('culqi:activation-check', $options);

        return [$code, Artisan::output()];
    }

    public function test_prepared_state_is_ready_and_purchases_stay_closed(): void
    {
        $this->fakeCulqiOk();
        [$code, $out] = $this->check();

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('RESULTADO: LISTO', $out);
        $this->assertStringContainsString('ESTADO: PREPARADO', $out);
        $this->assertStringContainsString('COMPRA_LIVE_VERIFICADA: no', $out);
        $this->assertStringNotContainsString('FALLA', $out);
        foreach ([self::PK, self::SK, self::HOOK] as $secret) {
            $this->assertStringNotContainsString($secret, $out);
        }
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), '/v2/charges'));
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_test_keys_are_not_ready_for_live(): void
    {
        config(['payments.culqi.env' => 'test', 'payments.culqi.public_key' => 'pk_test_AbCdEf0123456789', 'payments.culqi.private_key' => 'sk_test_ZyXwVu9876543210']);

        [$code, $out] = $this->check();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FALLA  No está en modo live', $out);
        $this->assertStringContainsString('RESULTADO: NO LISTO', $out);
    }

    public function test_a_changed_price_blocks_activation(): void
    {
        $this->fakeCulqiOk();
        config(['credits.packages.esencial.amount_pen' => '19.90']);

        [$code, $out] = $this->check();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FALLA  Los paquetes no coinciden con los precios autorizados', $out);
    }

    public function test_live_api_rejection_blocks_activation(): void
    {
        Http::fake(['https://api.culqi.com/*' => Http::response(['object' => 'error'], 401)]);

        [$code, $out] = $this->check();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('HTTP 401', $out);
    }

    public function test_mercadopago_new_purchases_or_disabled_webhooks_block_activation(): void
    {
        config(['payments.mercadopago.new_payments_enabled' => true, 'payments.culqi.webhooks_enabled' => false]);

        [$code, $out] = $this->check(['--offline' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('MERCADOPAGO_NEW_PAYMENTS_ENABLED está activo', $out);
        $this->assertStringContainsString('CULQI_WEBHOOKS_ENABLED no está activo', $out);
    }

    public function test_reports_test_and_general_activation_states_without_printing_emails(): void
    {
        config(['payments.provider' => 'culqi', 'payments.culqi.checkout_allowlist' => 'owner@example.test']);
        [, $out] = $this->check(['--offline' => true]);
        $this->assertStringContainsString('ESTADO: ACTIVO-PRUEBA (solo 1 cuenta(s)', $out);
        $this->assertStringNotContainsString('owner@example.test', $out);

        config(['payments.culqi.checkout_allowlist' => '']);
        [, $out] = $this->check(['--offline' => true]);
        $this->assertStringContainsString('ESTADO: ACTIVO-GENERAL', $out);
    }

    public function test_live_purchase_counts_as_verified_only_with_its_single_ledger_deposit(): void
    {
        $recharge = $this->recharge();
        PaymentOrder::create([
            'recharge_request_id' => $recharge->id, 'attempt_number' => 1, 'idempotency_key' => (string) Str::uuid(),
            'provider' => 'culqi', 'provider_order_id' => 'chr_live_VERIFIED0001', 'submission_status' => 'submitted',
            'status' => 'paid', 'amount_minor' => 1000, 'currency' => 'PEN', 'paid_at' => now(),
        ]);

        [, $out] = $this->check(['--offline' => true]);
        $this->assertStringContainsString('COMPRA_LIVE_VERIFICADA: no', $out, 'pagado pero sin depósito no basta');

        CreditTransaction::create([
            'teacher_profile_id' => $recharge->teacher_profile_id, 'recharge_request_id' => $recharge->id,
            'idempotency_key' => "recharge:{$recharge->id}:deposit", 'type' => 'deposit', 'amount' => 5, 'description' => 'Recarga',
        ]);

        [, $out] = $this->check(['--offline' => true]);
        $this->assertStringContainsString('COMPRA_LIVE_VERIFICADA: si', $out);
    }

    public function test_the_closed_switch_keeps_culqi_as_provider_but_reports_purchases_closed(): void
    {
        config(['payments.provider' => 'culqi', 'payments.culqi.checkout_allowlist' => 'compras-cerradas-abc123@mova.invalid']);

        [$code, $out] = $this->check(['--offline' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('ESTADO: PREPARADO (compras nuevas cerradas por el interruptor)', $out);
        $this->assertNull(\App\Support\CheckoutProvider::active(User::factory()->create(['email' => 'profe@example.test'])), 'ningún profesor real puede comprar');
    }

    public function test_mercadopago_open_is_never_reported_as_prepared(): void
    {
        config(['payments.mercadopago.new_payments_enabled' => true]);

        [$code, $out] = $this->check(['--offline' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('ESTADO: ABIERTO-MERCADOPAGO', $out);
    }

    public function test_a_paid_test_mode_charge_never_counts_as_a_verified_live_purchase(): void
    {
        $recharge = $this->recharge();
        PaymentOrder::create([
            'recharge_request_id' => $recharge->id, 'attempt_number' => 1, 'idempotency_key' => (string) Str::uuid(),
            'provider' => 'culqi', 'provider_order_id' => 'chr_test_SANDBOX0001', 'submission_status' => 'submitted',
            'status' => 'paid', 'amount_minor' => 1000, 'currency' => 'PEN', 'paid_at' => now(),
        ]);
        CreditTransaction::create([
            'teacher_profile_id' => $recharge->teacher_profile_id, 'recharge_request_id' => $recharge->id,
            'idempotency_key' => "recharge:{$recharge->id}:deposit", 'type' => 'deposit', 'amount' => 5, 'description' => 'Recarga',
        ]);

        [, $out] = $this->check(['--offline' => true]);

        $this->assertStringContainsString('COMPRA_LIVE_VERIFICADA: no', $out);
    }

    private function recharge(): RechargeRequest
    {
        $user = User::factory()->create();
        $user->assignRole('teacher');
        $profile = TeacherProfile::create(['user_id' => $user->id, 'is_verified' => true, 'credits_available' => 0, 'credits_reserved' => 0]);

        return RechargeRequest::create([
            'teacher_profile_id' => $profile->id, 'package_code' => 'inicio', 'package_name' => 'Inicio', 'credits' => 5,
            'amount_pen' => '10.00', 'payment_method' => 'culqi', 'operation_number' => null,
            'operation_number_normalized' => null, 'status' => 'approved',
        ]);
    }
}
