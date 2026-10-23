<?php

namespace Tests\Feature;

use App\Models\LegalAcceptance;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Support\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditPackagePricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_five_authorized_prices_and_volume_discounts_are_exact(): void
    {
        $expected = [
            'inicio' => [5, 1000, '10.00', 0, '0.00', '2.00'],
            'esencial' => [10, 1980, '20.00', 1, '0.20', '1.98'],
            'impulso' => [15, 2940, '30.00', 2, '0.60', '1.96'],
            'avance' => [20, 3880, '40.00', 3, '1.20', '1.94'],
            'pro' => [30, 5760, '60.00', 4, '2.40', '1.92'],
        ];
        $this->assertSame(array_keys($expected), array_column(Catalog::packages(), 'code'));
        foreach ($expected as $code => [$credits, $minor, $base, $discount, $saving, $unit]) {
            $p = Catalog::package($code);
            $this->assertSame([$credits, $minor, $base, $discount, $saving, $unit], [
                $p['credits'], $p['amount_minor'], $p['base_amount_pen'], $p['discount_percent'], $p['saving_pen'], $p['price_per_credit'],
            ]);
            $this->assertSame($credits * 200 * (100 - $discount), $minor * 100);
            $this->assertSame('PEN', $p['currency']);
            $this->get(route('catalog.package', $code))->assertOk();
        }
        $this->assertCount(5, array_unique(array_column(Catalog::packages(), 'image')));
    }

    public function test_checkout_ignores_tampered_prices_and_keeps_the_purchase_snapshot_after_catalog_changes(): void
    {
        config([
            'payments.enabled' => true, 'payments.provider' => 'culqi',
            'payments.culqi.env' => 'test', 'payments.culqi.public_key' => 'pk_test_AbCdEf0123456789',
            'payments.culqi.private_key' => 'sk_test_AbCdEf0123456789',
            'payments.culqi.webhooks_enabled' => false, 'payments.culqi.encrypt_payloads' => false,
            'payments.culqi.checkout_allowlist' => '',
        ]);
        $user = User::factory()->create();
        $user->assignRole('teacher');
        LegalAcceptance::recordMissingCurrent($user, null);
        TeacherProfile::create(['user_id' => $user->id, 'credits_available' => 0, 'credits_reserved' => 0]);
        $this->actingAs($user->fresh());

        foreach (Catalog::packages() as $p) {
            $this->post(route('teacher.credits.checkout.store'), [
                'package_code' => $p['code'], 'amount_pen' => '0.01', 'credits' => 9999, 'currency' => 'USD',
            ])->assertRedirect();
            $r = RechargeRequest::latest('id')->firstOrFail();
            $this->assertSame($p['amount_pen'], $r->amount_pen);
            $this->assertSame($p['credits'], $r->credits);
            config(["credits.packages.{$p['code']}.amount_pen" => '99.00', "credits.packages.{$p['code']}.credits" => 99]);
            $this->get(route('teacher.credits.checkout.show', $r))->assertInertia(fn ($page) => $page
                ->where('recharge.amount_pen', $p['amount_pen'])
                ->where('recharge.amount_minor', $p['amount_minor'])
                ->where('recharge.credits', $p['credits']));
            $this->assertSame('pending', $r->fresh()->status);
        }
        $this->postJson(route('teacher.credits.checkout.store'), ['package_code' => 'inventado'])->assertUnprocessable();
        $this->assertSame(5, RechargeRequest::count());
        $this->assertDatabaseCount('credit_transactions', 0);
    }
}
