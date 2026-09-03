<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\PaymentOrder;
use App\Models\PaymentWebhook;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Payment\Contracts\PaymentProviderContract;
use App\Payment\CulqiPaymentProvider;
use App\Payment\FakePaymentProvider;
use App\Services\PaymentWebhookService;
use App\Services\RechargeApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cubre la base del sistema de pagos automáticos añadida en esta ronda:
 * FakePaymentProvider, PaymentWebhookService (idempotencia de webhooks) y
 * RechargeApprovalService::reverse() (refund/chargeback). No prueba nada de
 * Culqi real — CulqiPaymentProvider es un stub deliberado hasta que exista
 * cuenta comercial (ver ese archivo).
 */
class PaymentOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_fake_provider_creates_payment_order_frozen_from_recharge_request(): void
    {
        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile, amountPen: '30.00', credits: 15);

        $order = app(FakePaymentProvider::class)->createPaymentAttempt($recharge);

        $this->assertSame($recharge->id, $order->recharge_request_id);
        $this->assertSame('fake', $order->provider);
        $this->assertSame('pending', $order->status);
        $this->assertSame(3000, $order->amount_minor);
        $this->assertSame('PEN', $order->currency);
        $this->assertNotNull($order->provider_order_id);
        $this->assertNotNull($order->expires_at);
    }

    public function test_a_recharge_request_cannot_have_two_payment_orders_with_the_same_attempt_number(): void
    {
        // UNIQUE(recharge_request_id, attempt_number) — reemplaza al viejo
        // UNIQUE(recharge_request_id) simple (ver migración
        // 2026_09_01_000002_add_attempt_number_to_payment_orders_table):
        // dos filas para el MISMO intento siguen bloqueadas; un SEGUNDO
        // intento legítimo (attempt_number distinto) sí está permitido —
        // ver MercadoPagoPaymentProviderTest para ese caso.
        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);
        app(FakePaymentProvider::class)->createPaymentAttempt($recharge); // attempt_number=1 por default

        $this->expectException(\Illuminate\Database\QueryException::class);
        PaymentOrder::create([
            'recharge_request_id' => $recharge->id,
            'attempt_number' => 1,
            'provider' => 'fake',
            'provider_order_id' => 'fake_otra',
            'status' => 'created',
            'amount_minor' => 1000,
            'currency' => 'PEN',
        ]);
    }

    public function test_webhook_paid_event_credits_teacher_exactly_once(): void
    {
        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile, amountPen: '10.00', credits: 5);
        $provider = app(FakePaymentProvider::class);
        $order = $provider->createPaymentAttempt($recharge);
        $event = $provider->simulatePaidEvent($order, eventId: 'evt_fixed_1');

        $webhook = app(PaymentWebhookService::class)->handle('fake', $event);

        $this->assertSame('processed', $webhook->status);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('approved', $recharge->fresh()->status);
        $this->assertNull($recharge->fresh()->reviewed_by); // acreditado por el sistema, no por un admin
        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "recharge:{$recharge->id}:deposit")->count());
    }

    public function test_duplicate_webhook_event_id_never_credits_twice(): void
    {
        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile, amountPen: '10.00', credits: 5);
        $provider = app(FakePaymentProvider::class);
        $order = $provider->createPaymentAttempt($recharge);
        $event = $provider->simulatePaidEvent($order, eventId: 'evt_fixed_dup');

        $service = app(PaymentWebhookService::class);
        $service->handle('fake', $event);
        $service->handle('fake', $event); // mismo event_id — reintento del proveedor

        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "recharge:{$recharge->id}:deposit")->count());
        $this->assertSame(1, PaymentWebhook::where('provider', 'fake')->where('event_id', 'evt_fixed_dup')->count());
    }

    public function test_two_different_paid_events_for_the_same_order_still_credit_once(): void
    {
        // Simula el caso real de un proveedor que reenvía el mismo pago con
        // un event_id DISTINTO (no un simple retry) — la protección real no
        // es el event_id del webhook sino el idempotency_key del ledger.
        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile, amountPen: '10.00', credits: 5);
        $provider = app(FakePaymentProvider::class);
        $order = $provider->createPaymentAttempt($recharge);
        $service = app(PaymentWebhookService::class);

        $service->handle('fake', $provider->simulatePaidEvent($order, eventId: 'evt_a'));
        $service->handle('fake', $provider->simulatePaidEvent($order, eventId: 'evt_b'));

        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "recharge:{$recharge->id}:deposit")->count());
    }

    public function test_webhook_failed_event_marks_order_failed_without_crediting(): void
    {
        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);
        $provider = app(FakePaymentProvider::class);
        $order = $provider->createPaymentAttempt($recharge);

        app(PaymentWebhookService::class)->handle('fake', $provider->simulateFailedEvent($order));

        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame('pending', $recharge->fresh()->status);
        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertDatabaseCount('credit_transactions', 0);
    }

    public function test_reverse_approved_recharge_creates_reversal_and_debits_balance(): void
    {
        [, $profile] = $this->teacher();
        $admin = $this->userWithRole('admin');
        $recharge = $this->recharge($profile, amountPen: '10.00', credits: 5);
        app(RechargeApprovalService::class)->credit($recharge, $admin->id);
        $this->assertSame(5, $profile->fresh()->credits_available);

        $result = app(RechargeApprovalService::class)->reverse($recharge, $admin->id, 'Chargeback reportado por el proveedor');

        $this->assertTrue($result['changed']);
        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertSame('reversed', $recharge->fresh()->status);
        $this->assertSame($admin->id, $recharge->fresh()->reversed_by);
        $this->assertDatabaseHas('credit_transactions', [
            'idempotency_key' => "recharge:{$recharge->id}:reversal",
            'type' => 'reversal',
            'amount' => -5,
        ]);
    }

    public function test_reverse_is_idempotent(): void
    {
        [, $profile] = $this->teacher();
        $admin = $this->userWithRole('admin');
        $recharge = $this->recharge($profile, amountPen: '10.00', credits: 5);
        app(RechargeApprovalService::class)->credit($recharge, $admin->id);

        app(RechargeApprovalService::class)->reverse($recharge, $admin->id, 'Primer intento');
        $second = app(RechargeApprovalService::class)->reverse($recharge, $admin->id, 'Segundo intento');

        $this->assertFalse($second['changed']);
        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "recharge:{$recharge->id}:reversal")->count());
    }

    public function test_a_reversal_can_leave_balance_negative_if_already_spent(): void
    {
        // Documenta el comportamiento deliberado: MOVA no inventa créditos
        // ni bloquea la cuenta en silencio si el profesor ya gastó lo
        // revertido — ver el comentario en RechargeApprovalService::reverse().
        [, $profile] = $this->teacher();
        $admin = $this->userWithRole('admin');
        $recharge = $this->recharge($profile, amountPen: '10.00', credits: 5);
        app(RechargeApprovalService::class)->credit($recharge, $admin->id);
        // Query builder directo, no Eloquent ->update(): $profile en memoria
        // sigue con su valor original (0) de antes del credit() de arriba
        // (que corrió sobre OTRA instancia del modelo), así que un
        // ->update() de Eloquent aquí lo vería como "sin cambios" y no
        // emitiría el UPDATE real.
        TeacherProfile::whereKey($profile->id)->update(['credits_available' => 0]); // simula que ya gastó los 5 créditos

        app(RechargeApprovalService::class)->reverse($recharge, $admin->id, 'Chargeback');

        $this->assertSame(-5, $profile->fresh()->credits_available);
    }

    public function test_cannot_reverse_a_recharge_that_was_never_approved(): void
    {
        [, $profile] = $this->teacher();
        $admin = $this->userWithRole('admin');
        $recharge = $this->recharge($profile);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(RechargeApprovalService::class)->reverse($recharge, $admin->id, 'No debería poder');
    }

    public function test_culqi_provider_is_a_stub_that_refuses_to_run(): void
    {
        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);
        $culqi = new CulqiPaymentProvider();

        $this->expectException(RuntimeException::class);
        $culqi->createPaymentAttempt($recharge);
    }

    public function test_container_resolves_fake_provider_by_default(): void
    {
        $this->assertInstanceOf(FakePaymentProvider::class, app(PaymentProviderContract::class));
    }

    public function test_container_resolves_culqi_provider_when_configured(): void
    {
        config(['payments.provider' => 'culqi']);

        $this->assertInstanceOf(CulqiPaymentProvider::class, app(PaymentProviderContract::class));
    }

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

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['password' => 'password']);
        $user->assignRole($role);

        return $user;
    }

    private function recharge(TeacherProfile $profile, string $amountPen = '10.00', int $credits = 5): RechargeRequest
    {
        $operation = fake()->unique()->numerify('OP########');

        return RechargeRequest::create([
            'teacher_profile_id' => $profile->id,
            'package_code' => 'inicio',
            'package_name' => 'Inicio',
            'credits' => $credits,
            'amount_pen' => $amountPen,
            'payment_method' => 'fake',
            'operation_number' => $operation,
            'operation_number_normalized' => $operation,
            'status' => 'pending',
        ]);
    }
}
