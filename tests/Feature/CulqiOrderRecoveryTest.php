<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\LegalAcceptance;
use App\Models\PaymentOrder;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\Concerns\FakesCulqi;
use Tests\TestCase;

/**
 * Incidente CCKT-400 (2026-11-13, recarga 4): el checkout de Culqi confirmó la orden (generó CIP/QR) y MOVA volvió a abrirlo
 * con esa misma orden; Culqi respondió CCKT-400 y el profesor quedó sin forma de ver su QR. Ahora una orden ya confirmada
 * se presenta con su QR/CIP vigentes (leídos de la API), nunca se crea otra mientras está pendiente, y nada acredita sin
 * `state=paid` confirmado por Culqi.
 */
class CulqiOrderRecoveryTest extends TestCase
{
    use FakesCulqi;
    use RefreshDatabase;

    private const QR = 'https://codigoqr.pagoefectivolatam.com/qr/abc123.png';

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('teacher', 'web');
        Notification::fake();
        config([
            'payments.enabled' => true,
            'payments.provider' => 'culqi',
            'payments.culqi.env' => 'test',
            'payments.culqi.public_key' => 'pk_test_AbCdEf0123456789',
            'payments.culqi.private_key' => 'sk_test_ZyXwVu9876543210',
            'payments.culqi.webhook_secret' => 'whsec_0123456789abcdef0123456789abcdef01234567',
            'payments.culqi.webhooks_enabled' => true,
            'payments.culqi.methods' => 'card,yape,order',
            'payments.culqi.checkout_allowlist' => '',
        ]);
    }

    /** @return array{0: User, 1: TeacherProfile, 2: RechargeRequest, 3: PaymentOrder} */
    private function pendingOrder(): array
    {
        $user = User::factory()->create(['name' => 'Abel Prueba', 'phone' => '987654321']);
        $user->assignRole('teacher');
        LegalAcceptance::recordMissingCurrent($user, null);
        $profile = TeacherProfile::create(['user_id' => $user->id, 'is_verified' => true, 'credits_available' => 0, 'credits_reserved' => 0]);
        $recharge = RechargeRequest::create([
            'teacher_profile_id' => $profile->id, 'package_code' => 'inicio', 'package_name' => 'Inicio', 'credits' => 5,
            'amount_pen' => '10.00', 'payment_method' => 'culqi', 'operation_number' => null, 'operation_number_normalized' => null, 'status' => 'pending',
        ]);
        $this->fakeCulqi(postOrder: fn (Request $r) => Http::response($this->orderFor($r->data(), ['id' => 'ord_test_RECOVERY0000001']), 201),
            getOrder: fn () => Http::response($this->orderFor([], ['id' => 'ord_test_RECOVERY0000001', 'amount' => 1000, 'state' => 'pending', 'metadata' => $this->orderPayloads[0]['metadata'] ?? []]), 200));
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.order', $recharge))->assertOk();

        return [$user, $profile, $recharge, PaymentOrder::sole()];
    }

    private function confirmedOrder(array $override = []): array
    {
        return $this->orderFor([], array_replace([
            'id' => 'ord_test_RECOVERY0000001', 'amount' => 1000, 'state' => 'pending',
            'payment_code' => '123456789', 'qr' => self::QR, 'url_pe' => 'https://pagoefectivolatam.com/x',
            'metadata' => $this->orderPayloads[0]['metadata'] ?? [], // la metadata real con la que MOVA creó la orden
        ], $override));
    }

    public function test_an_order_not_yet_confirmed_offers_to_open_the_culqi_checkout(): void
    {
        [$user, , $recharge] = $this->pendingOrder();
        cache()->flush();

        $json = $this->actingAs($user)->postJson(route('teacher.credits.checkout.refresh', $recharge))->assertOk()->json('action_required');

        $this->assertSame('order', $json['type']);
        $this->assertFalse($json['confirmed']);
        $this->assertNull($json['cip']);
        $this->assertNull($json['qr_url']);
    }

    public function test_a_confirmed_order_returns_its_qr_and_cip_so_the_checkout_is_not_reopened(): void
    {
        [$user, , $recharge] = $this->pendingOrder();
        cache()->flush();
        $this->fakeCulqi(getOrder: fn () => Http::response($this->confirmedOrder(), 200));

        $json = $this->actingAs($user)->postJson(route('teacher.credits.checkout.refresh', $recharge))->assertOk()->json();

        $this->assertSame('pending', $json['status']);
        $this->assertFalse($json['credits_credited']);
        $this->assertTrue($json['action_required']['confirmed']);
        $this->assertSame('123456789', $json['action_required']['cip']);
        $this->assertSame(self::QR, $json['action_required']['qr_url']);
        $this->assertStringContainsString('QR o el código CIP', $json['message']);
    }

    public function test_a_qr_from_an_unexpected_host_is_never_shown(): void
    {
        [$user, , $recharge] = $this->pendingOrder();
        cache()->flush();
        $this->fakeCulqi(getOrder: fn () => Http::response($this->confirmedOrder(['qr' => 'https://evil.example/qr.png']), 200));

        $json = $this->actingAs($user)->postJson(route('teacher.credits.checkout.refresh', $recharge))->json('action_required');

        $this->assertTrue($json['confirmed']);
        $this->assertNull($json['qr_url']);
        $this->assertSame('123456789', $json['cip']);
    }

    public function test_qr_urls_that_could_resolve_to_another_host_are_rejected(): void
    {
        [$user, , $recharge] = $this->pendingOrder();
        foreach (['https://evil.tld\@x.pagoefectivolatam.com/q.png', 'https://user@codigoqr.pagoefectivolatam.com/q.png', 'http://codigoqr.pagoefectivolatam.com/q.png', 'https://pagoefectivolatam.com.evil.tld/q.png'] as $bad) {
            cache()->flush();
            $this->fakeCulqi(getOrder: fn () => Http::response($this->confirmedOrder(['qr' => $bad]), 200));
            $this->assertNull($this->actingAs($user)->postJson(route('teacher.credits.checkout.refresh', $recharge))->json('action_required.qr_url'), $bad);
        }
    }

    public function test_another_teacher_cannot_read_the_order_qr_or_cip(): void
    {
        [, , $recharge] = $this->pendingOrder();
        $this->fakeCulqi(getOrder: fn () => Http::response($this->confirmedOrder(), 200));
        $intruder = User::factory()->create();
        $intruder->assignRole('teacher');

        $this->actingAs($intruder)->getJson(route('teacher.credits.checkout.status', $recharge))->assertForbidden();
        $this->actingAs($intruder)->postJson(route('teacher.credits.checkout.refresh', $recharge))->assertForbidden();
    }

    public function test_if_culqi_cannot_be_read_the_state_is_unknown_and_nothing_changes(): void
    {
        [$user, $profile, $recharge, $order] = $this->pendingOrder();
        cache()->flush();
        $this->fakeCulqi(getOrder: fn () => Http::response(['object' => 'error'], 500));

        $json = $this->actingAs($user)->postJson(route('teacher.credits.checkout.refresh', $recharge))->json();

        $this->assertSame('pending', $json['status']);
        $this->assertNull($json['action_required']['confirmed']);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, $profile->fresh()->credits_available);
    }

    public function test_a_confirmed_order_keeps_blocking_new_attempts_so_there_is_never_a_second_active_order_or_charge(): void
    {
        [$user, $profile, $recharge] = $this->pendingOrder();
        cache()->flush();
        $this->fakeCulqi(getOrder: fn () => Http::response($this->confirmedOrder(), 200));

        $this->actingAs($user)->postJson(route('teacher.credits.checkout.order', $recharge))->assertOk()->assertJsonPath('action_required.confirmed', true);
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => 'tkn_test_AbCdEf0123456789'])->assertOk()->assertJson(['status' => 'pending']);

        $this->assertSame(1, $this->culqiCalls['POST /v2/orders'], 'solo la orden original');
        $this->assertSame(0, $this->culqiCalls['POST /v2/charges']);
        $this->assertSame(1, PaymentOrder::count());
        $this->assertSame(0, CreditTransaction::count());
        $this->assertSame(0, $profile->fresh()->credits_available);
    }

    public function test_refreshing_a_confirmed_but_unpaid_order_never_credits(): void
    {
        [$user, $profile, $recharge, $order] = $this->pendingOrder();
        cache()->flush();
        $this->fakeCulqi(getOrder: fn () => Http::response($this->confirmedOrder(), 200));

        $this->actingAs($user)->postJson(route('teacher.credits.checkout.refresh', $recharge))->assertOk()->assertJson(['status' => 'pending']);

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, CreditTransaction::count());
        $this->assertSame(0, $profile->fresh()->credits_available);
    }

    public function test_checkout_client_errors_are_logged_with_the_code_only_and_are_owner_only(): void
    {
        [$user, , $recharge] = $this->pendingOrder();
        Log::spy();

        $this->actingAs($user)->postJson(route('teacher.credits.checkout.client-error', $recharge), ['flow' => 'order', 'code' => 'CCKT-400'])->assertNoContent();
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c) => str_contains($m, 'checkout de Culqi') && $c['code'] === 'CCKT-400' && $c['flow'] === 'order' && ! array_key_exists('email', $c));

        $this->actingAs($user)->postJson(route('teacher.credits.checkout.client-error', $recharge), ['flow' => 'order', 'code' => '<script>'])->assertStatus(422);
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.client-error', $recharge), ['flow' => 'otro', 'code' => 'CCKT-400'])->assertStatus(422);
        $this->actingAs($user)->postJson(route('teacher.credits.checkout.client-error', $recharge), ['flow' => 'order'])->assertNoContent();

        $intruder = User::factory()->create();
        $intruder->assignRole('teacher');
        $this->actingAs($intruder)->postJson(route('teacher.credits.checkout.client-error', $recharge), ['flow' => 'order', 'code' => 'CCKT-400'])->assertForbidden();
    }
}
