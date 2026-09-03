<?php

namespace Tests\Feature;

use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PAYMENT/STATUS RATE LIMITING (MOVA Yape Checkout Pre-Card Hardening).
 *
 * Las 4 rutas ya llevaban throttle:X,Y desde que se crearon
 * (CreditCheckoutController) — esta auditoría confirma dos cosas que
 * ninguna prueba verificaba todavía: (1) el límite es POR PROFESOR
 * autenticado, no por IP (throttle:X,Y sin nombre usa
 * ThrottleRequests::resolveRequestSignature(), que en una ruta autenticada
 * clave por user id — dos profesores distintos desde la misma IP no se
 * bloquean entre sí), y (2) un 429 nunca deja un PaymentOrder/
 * RechargeRequest en un estado corrupto ni parcialmente mutado — el
 * request ni siquiera llega al controlador.
 */
class CheckoutRateLimitingTest extends TestCase
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

    public function test_pay_endpoint_throttles_after_ten_attempts_per_minute_without_corrupting_state(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response([
            'id' => 777, 'status' => 'in_process', 'status_detail' => 'pending_contingency',
        ], 201)]);

        [$teacher, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        $last = null;
        for ($i = 0; $i < 11; $i++) {
            $last = $this->actingAs($teacher)
                ->postJson(route('teacher.credits.checkout.pay', $recharge), ['token' => 'tok-'.$i]);
        }

        $last->assertStatus(429);
        // La orden ya se resolvió en el primer intento (submission_status
        // pasa a 'submitted' con provider_order_id fijado) — los 10 POST
        // siguientes son no-ops por el guard de reintento existente, y el
        // 11º ni siquiera llegó al controlador. Ningún estado corrupto.
        $this->assertSame(0, $profile->fresh()->credits_available);
    }

    /**
     * Dos profesores distintos comparten la MISMA IP en un test HTTP (no
     * hay concepto de IP real aquí), así que el hecho de que ambos puedan
     * agotar su propio límite de forma INDEPENDIENTE ya demuestra que la
     * clave es por usuario autenticado, no por IP — si fuera por IP, el
     * segundo profesor heredaría el conteo del primero y su primer request
     * ya vendría bloqueado.
     */
    public function test_pay_rate_limit_is_isolated_per_teacher_not_shared_by_ip(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response([
            'id' => 778, 'status' => 'in_process', 'status_detail' => 'pending_contingency',
        ], 201)]);

        [$teacherA, $profileA] = $this->teacher();
        [$teacherB, $profileB] = $this->teacher();
        $rechargeA = $this->recharge($profileA);
        $rechargeB = $this->recharge($profileB);

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($teacherA)
                ->postJson(route('teacher.credits.checkout.pay', $rechargeA), ['token' => 'tok-a-'.$i]);
        }

        // El profesor A ya agotó su propio límite...
        $this->actingAs($teacherA)
            ->postJson(route('teacher.credits.checkout.pay', $rechargeA), ['token' => 'tok-a-overflow'])
            ->assertStatus(429);

        // ...pero el profesor B (misma IP de test) todavía tiene su cupo intacto.
        $this->actingAs($teacherB)
            ->postJson(route('teacher.credits.checkout.pay', $rechargeB), ['payment_method' => 'yape', 'token' => 'tok-b-1'])
            ->assertStatus(200);
    }

    public function test_status_endpoint_throttles_after_sixty_polls_per_minute(): void
    {
        [$teacher, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        $last = null;
        for ($i = 0; $i < 61; $i++) {
            $last = $this->actingAs($teacher)->getJson(route('teacher.credits.checkout.status', $recharge));
        }

        $last->assertStatus(429);
    }

    /**
     * STATUS SEMANTICS (MOVA Yape Final Pre-Card Gate): refresh() (POST) es
     * ahora el endpoint que el polling real llama — debe conservar el mismo
     * límite que status() tenía antes de la separación.
     */
    public function test_refresh_endpoint_throttles_after_sixty_polls_per_minute(): void
    {
        [$teacher, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        $last = null;
        for ($i = 0; $i < 61; $i++) {
            $last = $this->actingAs($teacher)->postJson(route('teacher.credits.checkout.refresh', $recharge));
        }

        $last->assertStatus(429);
    }

    // ---- helpers ---------------------------------------------------------

    private function teacher(): array
    {
        $teacher = User::factory()->create(['password' => 'password']);
        $teacher->assignRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_verified' => true,
            'credits_available' => 0,
            'credits_reserved' => 0,
        ]);

        return [$teacher, $profile];
    }

    private function recharge(TeacherProfile $profile): RechargeRequest
    {
        return RechargeRequest::create([
            'teacher_profile_id' => $profile->id,
            'package_code' => 'inicio',
            'package_name' => 'Inicio',
            'credits' => 5,
            'amount_pen' => '10.00',
            'payment_method' => 'mercadopago',
            'operation_number' => null,
            'operation_number_normalized' => null,
            'status' => 'pending',
        ]);
    }
}
