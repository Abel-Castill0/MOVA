<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\PaymentOrder;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Payment\MercadoPagoPaymentProvider;
use App\Payment\Money;
use App\Services\MercadoPagoPaymentReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * UNKNOWN PAYMENT RECOVERY + REVIEW AUDIT (ronda de hardening
 * distribuido) — cubre searchPaymentsByExternalReference() y
 * reconcileUncertainSubmission() directamente. Distinto de
 * MercadoPagoWebhookRecoveryServiceTest (que cubre el BARRIDO en lote) y de
 * MercadoPagoPaymentProviderTest (que cubre el gate SÍNCRONO dentro de
 * createPaymentAttempt()) — aquí se prueba el método compartido que ambos
 * llaman, sin pasar por ninguno de los dos.
 */
class MercadoPagoUncertainSubmissionRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payments.mercadopago.base_url' => 'https://api.mercadopago.com',
            'payments.mercadopago.access_token' => 'unit-test-placeholder-000',
        ]);
    }

    // ---- searchPaymentsByExternalReference() — directo -----------------------

    public function test_search_returns_empty_array_on_zero_results(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/search*' => Http::response(
            ['paging' => ['total' => 0, 'limit' => 10, 'offset' => 0], 'results' => []],
            200
        )]);

        $results = (new MercadoPagoPaymentProvider())->searchPaymentsByExternalReference('recharge:1:attempt:1');

        $this->assertSame([], $results);
    }

    public function test_search_returns_mapped_results_on_one_match(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/search*' => Http::response([
            'paging' => ['total' => 1, 'limit' => 10, 'offset' => 0],
            'results' => [['id' => 42, 'status' => 'approved', 'status_detail' => 'accredited', 'transaction_amount' => 10.0, 'external_reference' => 'recharge:1:attempt:1', 'currency_id' => 'PEN']],
        ], 200)]);

        $results = (new MercadoPagoPaymentProvider())->searchPaymentsByExternalReference('recharge:1:attempt:1');

        $this->assertCount(1, $results);
        $this->assertSame('42', $results[0]['id']);
        $this->assertSame('approved', $results[0]['status']);
    }

    public function test_search_returns_null_on_transport_exception(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/search*' => function () {
            throw new ConnectionException('timeout');
        }]);

        $this->assertNull((new MercadoPagoPaymentProvider())->searchPaymentsByExternalReference('recharge:1:attempt:1'));
    }

    public function test_search_returns_null_on_error_status(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/search*' => Http::response(['message' => 'down'], 500)]);

        $this->assertNull((new MercadoPagoPaymentProvider())->searchPaymentsByExternalReference('recharge:1:attempt:1'));
    }

    public function test_search_returns_null_without_access_token(): void
    {
        config(['payments.mercadopago.access_token' => null]);
        Http::fake();

        $this->assertNull((new MercadoPagoPaymentProvider())->searchPaymentsByExternalReference('recharge:1:attempt:1'));
        Http::assertNothingSent();
    }

    // ---- reconcileUncertainSubmission() — cero resultados ---------------------

    public function test_zero_results_stays_uncertain_and_increments_budget_without_reaching_it(): void
    {
        [$order] = $this->uncertainOrder();
        $this->fakeSearch([]);

        $outcome = $this->reconcile($order, maxAttempts: 5);

        $this->assertSame('still_uncertain', $outcome);
        $fresh = $order->fresh();
        $this->assertSame('uncertain', $fresh->submission_status);
        $this->assertSame(1, $fresh->recovery_attempts);
        $this->assertNull($fresh->review_reason);
    }

    public function test_zero_results_repeated_until_budget_exhausted_falls_to_review(): void
    {
        [$order] = $this->uncertainOrder();
        $this->fakeSearch([]);

        // Presupuesto de 2: 1er barrido → recovery_attempts=1 (bajo el
        // tope); 2do barrido → recovery_attempts=2, alcanza el tope → exhausted.
        $this->assertSame('still_uncertain', $this->reconcile($order, maxAttempts: 2));
        $outcome = $this->reconcile($order, maxAttempts: 2);

        $this->assertSame('exhausted', $outcome);
        $fresh = $order->fresh();
        $this->assertNull($fresh->submission_status); // ya no se vuelve a seleccionar en el barrido en lote
        $this->assertSame(2, $fresh->recovery_attempts);
        $this->assertNotNull($fresh->review_reason);
        $this->assertStringContainsString('agotó el presupuesto', $fresh->review_reason);

        // REVIEW AUDIT: episodio de revisión recién detectado.
        $this->assertNotNull($fresh->review_detected_at);
        $this->assertNull($fresh->review_resolved_at);
    }

    public function test_search_transport_failure_does_not_consume_budget(): void
    {
        [$order] = $this->uncertainOrder();
        Http::fake(['api.mercadopago.com/v1/payments/search*' => function () {
            throw new ConnectionException('timeout');
        }]);

        $outcome = $this->reconcile($order, maxAttempts: 5);

        $this->assertSame('search_failed', $outcome);
        $fresh = $order->fresh();
        $this->assertSame('uncertain', $fresh->submission_status);
        $this->assertSame(0, $fresh->recovery_attempts); // no gastó presupuesto
    }

    // ---- reconcileUncertainSubmission() — múltiples resultados ----------------

    public function test_multiple_results_is_an_anomaly_never_picks_one_never_credits(): void
    {
        [$order, $recharge, $profile] = $this->uncertainOrder();
        $this->fakeSearch([
            ['id' => 1, 'status' => 'approved', 'status_detail' => 'accredited', 'transaction_amount' => 10.0, 'external_reference' => $order->externalReference(), 'currency_id' => 'PEN'],
            ['id' => 2, 'status' => 'approved', 'status_detail' => 'accredited', 'transaction_amount' => 10.0, 'external_reference' => $order->externalReference(), 'currency_id' => 'PEN'],
        ]);

        $outcome = $this->reconcile($order);

        $this->assertSame('ambiguous', $outcome);
        $fresh = $order->fresh();
        $this->assertNull($fresh->provider_order_id);
        $this->assertNull($fresh->submission_status);
        $this->assertNotNull($fresh->review_reason);
        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertSame(0, CreditTransaction::count());
    }

    public function test_one_result_with_mismatched_amount_goes_to_review_without_crediting(): void
    {
        [$order, , $profile] = $this->uncertainOrder(amountPen: '10.00');
        $this->fakeSearch([
            ['id' => 9, 'status' => 'approved', 'status_detail' => 'accredited', 'transaction_amount' => 999.0, 'external_reference' => $order->externalReference(), 'currency_id' => 'PEN'],
        ]);

        $outcome = $this->reconcile($order);

        $this->assertSame('ambiguous', $outcome);
        $fresh = $order->fresh();
        $this->assertNull($fresh->provider_order_id);
        $this->assertNotNull($fresh->review_reason);
        $this->assertSame(0, $profile->fresh()->credits_available);
    }

    // ---- reconcileUncertainSubmission() — resuelto -----------------------------

    public function test_one_matching_result_resolves_persists_id_and_credits_exactly_once(): void
    {
        [$order, $recharge, $profile] = $this->uncertainOrder(amountPen: '10.00', credits: 5);
        $this->fakeSearchAndFetch($order, id: 555, status: 'approved', statusDetail: 'accredited', amount: 10.0);

        $outcome = $this->reconcile($order);

        $this->assertSame('resolved', $outcome);
        $fresh = $order->fresh();
        $this->assertSame('555', $fresh->provider_order_id);
        $this->assertSame('submitted', $fresh->submission_status);
        $this->assertSame('paid', $fresh->status);
        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "recharge:{$recharge->id}:deposit")->count());

        // Idempotente: la fila ya no está 'uncertain' — una segunda llamada
        // es un no-op (nunca un segundo crédito).
        $second = $this->reconcile($order->fresh());
        $this->assertSame('resolved', $second);
        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::count());
    }

    /**
     * FAILURE BOUNDARY #3 (POST SUCCEEDED / LOCAL UPDATE FAILED): simula
     * determinísticamente el estado exacto que dejaría esa frontera de
     * fallo — Mercado Pago SÍ creó el pago, pero la persistencia local del
     * provider_order_id nunca llegó a correr. El estado en base de datos es
     * `submission_status='submitting'` (la marca pre-I/O persistida ANTES
     * del POST — ver MercadoPagoPaymentProvider::createPaymentAttempt() —
     * es exactamente lo que queda sin tocar si el UPDATE final que hubiera
     * escrito 'submitted'+provider_order_id nunca corrió), provider_order_id
     * NULL. No hace falta inducir una caída real de MySQL — el estado en la
     * fila es lo único que importa para probar que la recuperación lo
     * encuentra y acredita EXACTAMENTE una vez.
     */
    public function test_provider_succeeded_but_local_persistence_lost_the_id_is_recovered_and_credits_once(): void
    {
        [$order, $recharge, $profile] = $this->uncertainOrder(amountPen: '15.00', credits: 8, submissionStatus: 'submitting');
        $this->fakeSearchAndFetch($order, id: 777, status: 'approved', statusDetail: 'accredited', amount: 15.0);

        $outcome = $this->reconcile($order);

        $this->assertSame('resolved', $outcome);
        $this->assertSame('777', $order->fresh()->provider_order_id);
        $this->assertSame('submitted', $order->fresh()->submission_status);
        $this->assertSame(8, $profile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "recharge:{$recharge->id}:deposit")->count());

        // Un segundo intento de recuperación (ej. el barrido en lote
        // corriendo después) no debe duplicar nada.
        $again = $this->reconcile($order->fresh());
        $this->assertSame('resolved', $again);
        $this->assertSame(8, $profile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::count());
    }

    /**
     * FAILURE BOUNDARY #4: la búsqueda por external_reference debe poder
     * encontrar y recuperar un intento incierto SIN IMPORTAR el estado de
     * negocio del pago encontrado — approved, pending, o incluso rejected
     * (un pago rechazado SIGUE siendo un recurso remoto real con id, ver la
     * sección PRESERVA). No filtra solo "éxito": searchResultMatchesOrder()
     * únicamente valida contexto (referencia/monto/moneda/cuenta), nunca el
     * campo `status`.
     *
     * @dataProvider anyBusinessStatus
     */
    public function test_search_recovers_regardless_of_the_payments_business_status(string $status, ?string $statusDetail, string $expectedLocalStatus): void
    {
        [$order, , $profile] = $this->uncertainOrder(amountPen: '20.00', credits: 3, submissionStatus: 'submitting');
        $this->fakeSearchAndFetch($order, id: 4242, status: $status, statusDetail: $statusDetail, amount: 20.0);

        $outcome = $this->reconcile($order);

        $this->assertSame('resolved', $outcome);
        $fresh = $order->fresh();
        $this->assertSame('4242', $fresh->provider_order_id); // SIEMPRE recuperado
        $this->assertSame($expectedLocalStatus, $fresh->status);
        $this->assertSame($expectedLocalStatus === 'paid' ? 3 : 0, $profile->fresh()->credits_available);
    }

    public static function anyBusinessStatus(): array
    {
        return [
            'approved/accredited' => ['approved', 'accredited', 'paid'],
            'in_process (pending)' => ['in_process', null, 'pending'],
            'rejected' => ['rejected', 'cc_rejected_other_reason', 'failed'],
        ];
    }

    // ---- LOST-CREATE-RESPONSE RECOVERY (ronda de recovery-gap) ----------------
    //
    // Modela: POST /v1/payments llega a Mercado Pago, crea un
    // pending_challenge, pero MOVA nunca recibe/persiste esa respuesta
    // (excepción de red) — el intento local queda 'uncertain', sin ningún
    // dato de Challenge. La recuperación por external_reference encuentra el
    // pago vía /search (resumen, SIN three_ds_info — ver SEARCH SUMMARY
    // FALLBACK) y reconcile() hace su propio GET canónico por id, que SÍ
    // trae three_ds_info — ese GET es lo que realmente recupera el
    // Challenge, ver MercadoPagoPaymentReconciliationService::
    // recoverChallengeFieldsIfMissing().

    public function test_lost_create_response_recovers_challenge_fields_via_the_canonical_get_and_credits_nothing(): void
    {
        [$order, , $profile] = $this->uncertainOrder(amountPen: '10.00', credits: 5);
        $this->fakeSearchAndFetchWithChallenge(
            $order,
            id: 600,
            challengeUrl: 'https://acs-public.tp.mastercard.com/api/v1/browser_Challenges',
            creq: 'eyJmYWtlIjoiY3JlcSJ9'
        );

        $before = now();
        $outcome = $this->reconcile($order);

        $this->assertSame('resolved', $outcome);
        $fresh = $order->fresh();
        $this->assertSame('600', $fresh->provider_order_id);
        $this->assertSame('pending', $fresh->status);
        $this->assertSame('https://acs-public.tp.mastercard.com/api/v1/browser_Challenges', $fresh->three_ds_challenge_url);
        $this->assertSame('eyJmYWtlIjoiY3JlcSJ9', $fresh->three_ds_creq);
        $this->assertNotNull($fresh->three_ds_expires_at);
        $this->assertTrue($fresh->three_ds_expires_at->between($before->clone()->addMinutes(4), $before->clone()->addMinutes(6)));
        $this->assertSame(0, $profile->fresh()->credits_available, 'un pending_challenge recuperado nunca acredita');
    }

    /**
     * CHALLENGE EXPIRY MUST NEVER SLIDE — también para un Challenge
     * RECUPERADO (no solo el capturado en createPaymentAttempt(), ver
     * MercadoPagoPaymentProviderTest::test_challenge_expiry_never_slides_across_repeated_reconciliation()):
     * una vez que recoverChallengeFieldsIfMissing() fija
     * `three_ds_expires_at` la primera vez, reconciliaciones posteriores
     * mientras el Challenge sigue sin resolverse nunca la reinician.
     */
    public function test_recovered_challenge_expiry_never_slides_across_repeated_reconciliation(): void
    {
        Carbon::setTestNow('2026-09-03 11:00:00');

        [$order] = $this->uncertainOrder(amountPen: '10.00');
        $this->fakeSearchAndFetchWithChallenge(
            $order,
            id: 601,
            challengeUrl: 'https://acs-public.tp.mastercard.com/api/v1/browser_Challenges',
            creq: 'eyJmYWtlIjoiY3JlcSJ9'
        );

        $this->reconcile($order);
        $recoveredExpiry = $order->fresh()->three_ds_expires_at;
        $this->assertSame('2026-09-03 11:05:00', $recoveredExpiry->toDateTimeString());

        // Reconciliación normal posterior (ya no pasa por
        // reconcileUncertainSubmission() — el intento ya tiene
        // provider_order_id) mientras el Challenge sigue abierto.
        Carbon::setTestNow('2026-09-03 11:03:00');
        Http::fake(['api.mercadopago.com/v1/payments/601' => Http::response([
            'id' => 601,
            'status' => 'pending',
            'status_detail' => 'pending_challenge',
            'transaction_amount' => 10.0,
            'transaction_amount_refunded' => 0,
            'currency_id' => 'PEN',
            'external_reference' => $order->externalReference(),
        ], 200)]);

        app(MercadoPagoPaymentReconciliationService::class)
            ->reconcile($order->fresh(), null, app(MercadoPagoPaymentProvider::class));

        $this->assertSame($recoveredExpiry->toDateTimeString(), $order->fresh()->three_ds_expires_at->toDateTimeString());
        $this->assertSame('eyJmYWtlIjoiY3JlcSJ9', $order->fresh()->three_ds_creq);

        Carbon::setTestNow();
    }

    /**
     * CHALLENGE URL SAFETY también en el camino de recuperación: un
     * external_resource_url no-HTTPS llegado vía el GET canónico de
     * recuperación nunca se persiste — ni siquiera parcialmente (creq solo,
     * sin URL) — y el intento sigue 'pending' sin acreditar nada. El pago SÍ
     * se recupera (provider_order_id se fija igual — es un recurso remoto
     * real), solo el Challenge se descarta.
     */
    public function test_unsafe_challenge_url_arriving_through_recovery_is_never_persisted_and_credits_nothing(): void
    {
        [$order, , $profile] = $this->uncertainOrder(amountPen: '10.00', credits: 5);
        $this->fakeSearchAndFetchWithChallenge(
            $order,
            id: 602,
            challengeUrl: 'javascript:alert(1)',
            creq: 'eyJmYWtlIjoiY3JlcSJ9'
        );

        $outcome = $this->reconcile($order);

        $this->assertSame('resolved', $outcome);
        $fresh = $order->fresh();
        $this->assertSame('602', $fresh->provider_order_id);
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->three_ds_challenge_url);
        $this->assertNull($fresh->three_ds_creq);
        $this->assertNull($fresh->three_ds_expires_at);
        $this->assertSame(0, $profile->fresh()->credits_available);
    }

    // ---- REVIEW AUDIT — tres estados distinguibles -----------------------------

    public function test_review_audit_distinguishes_never_active_and_resolved(): void
    {
        [$order] = $this->uncertainOrder(amountPen: '10.00');

        // Estado 1: nunca hubo anomalía.
        $fresh = $order->fresh();
        $this->assertNull($fresh->review_detected_at);
        $this->assertNull($fresh->review_resolved_at);

        // UN SOLO Http::fake() con un closure que cuenta llamadas para TODO
        // el test — dos Http::fake() sueltos para el mismo patrón de URL no
        // se reemplazan de forma confiable (ver
        // MercadoPagoPaymentProviderTest), así que las tres fases ("no
        // coincide" x2, luego "coincide") viven en un único registro.
        $searchCalls = 0;
        Http::fake([
            'api.mercadopago.com/v1/payments/search*' => function () use (&$searchCalls, $order) {
                $searchCalls++;
                $mismatch = $searchCalls <= 2;

                return Http::response([
                    'paging' => ['total' => 1, 'limit' => 10, 'offset' => 0],
                    'results' => [[
                        'id' => $mismatch ? 1 : 321,
                        'status' => 'approved',
                        'status_detail' => 'accredited',
                        'transaction_amount' => $mismatch ? 999.0 : 10.0,
                        'external_reference' => $order->externalReference(),
                        'currency_id' => 'PEN',
                    ]],
                ], 200);
            },
            'api.mercadopago.com/v1/payments/321' => Http::response([
                'id' => 321,
                'status' => 'approved',
                'status_detail' => 'accredited',
                'transaction_amount' => 10.0,
                'transaction_amount_refunded' => 0,
                'external_reference' => $order->externalReference(),
                'currency_id' => 'PEN',
            ], 200),
        ]);

        // Estado 2: anomalía activa (búsqueda con resultado que no coincide).
        $this->reconcile($order);
        $fresh = $order->fresh();
        $this->assertNotNull($fresh->review_detected_at);
        $this->assertNull($fresh->review_resolved_at);
        $detectedAt = $fresh->review_detected_at;

        // Repetir la misma anomalía NO reinicia el reloj — se vuelve a
        // marcar 'uncertain' a mano para simular otro barrido sobre el
        // mismo intento incierto.
        $fresh->update(['submission_status' => 'uncertain']);
        $this->reconcile($order->fresh());
        $this->assertTrue($order->fresh()->review_detected_at->equalTo($detectedAt));

        // Estado 3: anomalía resuelta — un intento nuevo que sí reconcilia
        // limpio debe fijar review_resolved_at sin perder review_detected_at.
        $order->fresh()->update(['submission_status' => 'uncertain']);
        $this->reconcile($order->fresh());

        $resolved = $order->fresh();
        $this->assertNull($resolved->review_reason);
        $this->assertTrue($resolved->review_detected_at->equalTo($detectedAt));
        $this->assertNotNull($resolved->review_resolved_at);
    }

    // ---- helpers ----------------------------------------------------------------

    private function reconcile(PaymentOrder $order, ?int $maxAttempts = null): string
    {
        return app(MercadoPagoPaymentReconciliationService::class)->reconcileUncertainSubmission(
            $order,
            app(MercadoPagoPaymentProvider::class),
            $maxAttempts
        );
    }

    private function fakeSearch(array $results): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/search*' => Http::response(
            ['paging' => ['total' => count($results), 'limit' => 10, 'offset' => 0], 'results' => $results],
            200
        )]);
    }

    private function fakeSearchAndFetch(PaymentOrder $order, int $id, string $status, ?string $statusDetail, float $amount): void
    {
        $body = [
            'id' => $id,
            'status' => $status,
            'status_detail' => $statusDetail,
            'transaction_amount' => $amount,
            'transaction_amount_refunded' => 0,
            'external_reference' => $order->externalReference(),
            'currency_id' => 'PEN',
        ];

        Http::fake([
            'api.mercadopago.com/v1/payments/search*' => Http::response(
                ['paging' => ['total' => 1, 'limit' => 10, 'offset' => 0], 'results' => [$body]],
                200
            ),
            "api.mercadopago.com/v1/payments/{$id}" => Http::response($body, 200),
        ]);
    }

    /**
     * Variante de fakeSearchAndFetch() para el escenario LOST-CREATE-RESPONSE:
     * el resumen de /search NUNCA trae `three_ds_info` (SEARCH SUMMARY
     * FALLBACK) — solo el GET canónico por id, que es el que
     * recoverChallengeFieldsIfMissing() realmente consume.
     */
    private function fakeSearchAndFetchWithChallenge(PaymentOrder $order, int $id, ?string $challengeUrl, ?string $creq): void
    {
        $searchBody = [
            'id' => $id,
            'status' => 'pending',
            'status_detail' => 'pending_challenge',
            'transaction_amount' => 10.0,
            'external_reference' => $order->externalReference(),
            'currency_id' => 'PEN',
        ];

        $fetchBody = $searchBody + [
            'transaction_amount_refunded' => 0,
            'three_ds_info' => ['external_resource_url' => $challengeUrl, 'creq' => $creq],
        ];

        Http::fake([
            'api.mercadopago.com/v1/payments/search*' => Http::response(
                ['paging' => ['total' => 1, 'limit' => 10, 'offset' => 0], 'results' => [$searchBody]],
                200
            ),
            "api.mercadopago.com/v1/payments/{$id}" => Http::response($fetchBody, 200),
        ]);
    }

    /**
     * @return array{0:PaymentOrder,1:RechargeRequest,2:TeacherProfile}
     */
    private function uncertainOrder(string $amountPen = '10.00', int $credits = 5, string $submissionStatus = 'uncertain'): array
    {
        $teacher = User::factory()->create(['password' => 'password']);
        $teacher->assignRole('teacher');
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_verified' => true,
            'credits_available' => 0,
            'credits_reserved' => 0,
        ]);

        $operation = fake()->unique()->numerify('OP########');
        $recharge = RechargeRequest::create([
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

        $order = PaymentOrder::create([
            'recharge_request_id' => $recharge->id,
            'attempt_number' => 1,
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'provider' => 'mercadopago',
            'provider_order_id' => null,
            'status' => 'pending',
            'submission_status' => $submissionStatus,
            'amount_minor' => Money::solesToMinor($amountPen),
            'currency' => 'PEN',
        ]);

        return [$order, $recharge, $profile];
    }
}
