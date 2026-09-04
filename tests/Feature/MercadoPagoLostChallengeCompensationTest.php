<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\PaymentOrder;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Payment\Contracts\YapePaymentInstrument;
use App\Payment\MercadoPagoPaymentProvider;
use App\Payment\Money;
use App\Services\MercadoPagoPaymentReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * COMPENSATING CANCELLATION (LOST 3DS CHALLENGE) — cubre
 * MercadoPagoPaymentReconciliationService::compensateLostChallenge() e
 * isLostChallengeCompensationCandidate() directamente. Distinto de
 * MercadoPagoUncertainSubmissionRecoveryTest (que cubre el OTRO
 * presupuesto de recuperación — submission_status en
 * 'submitting'/'uncertain', provider_order_id todavía desconocido) — aquí
 * el pago YA tiene provider_order_id confirmado (`submission_status
 * ='submitted'`) y el problema es específicamente un Challenge 3DS cuyos
 * datos MOVA nunca pudo capturar/recuperar.
 *
 * Escenarios A-F del encargo, en el mismo orden que la sección 6.
 */
class MercadoPagoLostChallengeCompensationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payments.mercadopago.base_url' => 'https://api.mercadopago.com',
            'payments.mercadopago.access_token' => 'unit-test-placeholder-000',
            'payments.mercadopago.recovery.stuck_order_minutes' => 30,
        ]);
    }

    // ---- A. unrecoverable pending_challenge → cancelación exitosa -------------

    public function test_a_unrecoverable_challenge_is_cancelled_zero_credits_and_retry_allowed_afterward(): void
    {
        [$order, $recharge, $profile] = $this->lostChallengeOrder();

        $this->fakeCancellableFlow($order);

        $outcome = $this->compensate($order);

        $this->assertSame('failed', $outcome);
        $fresh = $order->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame('cancelled', $fresh->provider_status);
        $this->assertNull($fresh->three_ds_challenge_url);
        $this->assertNull($fresh->three_ds_creq);
        $this->assertNull($fresh->three_ds_expires_at);
        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertSame(0, CreditTransaction::count());
        $this->assertSame('pending', $recharge->fresh()->status);

        // Retry allowed ONLY afterward: resolveAttemptRow() ya trata
        // 'failed' como terminal-reintentable — se prueba de verdad
        // llamando createPaymentAttempt(), no solo inspeccionando el enum.
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response(
            ['id' => 999, 'status' => 'pending', 'status_detail' => 'pending_contingency'],
            201
        )]);

        $second = (new MercadoPagoPaymentProvider())->createPaymentAttempt(
            $recharge->fresh(),
            new YapePaymentInstrument(token: 'yape-retry-after-compensation')
        );

        $this->assertSame(2, $second->attempt_number);
        $this->assertNotSame($fresh->idempotency_key, $second->idempotency_key);
    }

    // ---- B. RACE: se aprueba antes/durante la cancelación ----------------------

    public function test_b_race_approved_during_cancellation_never_forces_local_failure_credits_exactly_once(): void
    {
        [$order, $recharge, $profile] = $this->lostChallengeOrder(credits: 7);

        $approved = false;
        $id = $order->provider_order_id;
        Http::fake([
            "api.mercadopago.com/v1/payments/{$id}" => function ($request) use (&$approved, $id, $order) {
                if ($request->method() === 'PUT') {
                    // La carrera ya sucedió: Mercado Pago rechaza la
                    // cancelación (400, documentado: "solo se puede
                    // cancelar pending/in_process") porque el pago se
                    // aprobó justo antes/durante esta llamada.
                    $approved = true;

                    return Http::response(['message' => 'invalid status for cancellation'], 400);
                }

                $truth = $approved
                    ? ['status' => 'approved', 'status_detail' => 'accredited']
                    : ['status' => 'pending', 'status_detail' => 'pending_challenge'];

                return Http::response($this->truthBody($order, $id) + $truth, 200);
            },
        ]);

        $outcome = $this->compensate($order);

        $this->assertSame('paid', $outcome);
        $fresh = $order->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertNotSame('failed', $fresh->status, 'la cancelación nunca debe forzar failed cuando el proveedor ya aprobó');
        $this->assertSame(7, $profile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "recharge:{$recharge->id}:deposit")->count());
        $this->assertSame('approved', $recharge->fresh()->status);
    }

    // ---- C. Falla de transporte en la cancelación -------------------------------

    public function test_c_transport_failure_never_fakes_failure_stays_recoverable(): void
    {
        [$order] = $this->lostChallengeOrder();

        $id = $order->provider_order_id;
        $getCalls = 0;
        Http::fake([
            "api.mercadopago.com/v1/payments/{$id}" => function ($request) use (&$getCalls, $order, $id) {
                if ($request->method() === 'PUT') {
                    throw new ConnectionException('cancel timeout');
                }

                $getCalls++;
                if ($getCalls === 1) {
                    return Http::response($this->truthBody($order, $id) + ['status' => 'pending', 'status_detail' => 'pending_challenge'], 200);
                }

                throw new ConnectionException('final GET timeout');
            },
        ]);

        $outcome = $this->compensate($order);

        $this->assertSame('transport_uncertain', $outcome);
        $fresh = $order->fresh();
        $this->assertSame('pending', $fresh->status, 'nunca se fabrica un failed local por una falla de transporte');
        // El claim (compensation_claimed_at) sigue en pie — "no premature
        // retry": expira solo tras COMPENSATION_CLAIM_STALE_MINUTES, nunca
        // antes. El intento queda recuperable para el próximo barrido de
        // todas formas (ver test de recuperación tras crash, más abajo).
        $this->assertNotNull($fresh->compensation_claimed_at);
        $this->assertSame(0, CreditTransaction::count());
    }

    // ---- D. Dos workers concurrentes --------------------------------------------

    public function test_d_two_sequential_compensation_workers_produce_one_coherent_outcome_no_duplicates(): void
    {
        [$order, , $profile] = $this->lostChallengeOrder();

        $this->fakeCancellableFlow($order);

        $first = $this->compensate($order);
        $this->assertSame('failed', $first);

        // "Worker" B llega después (mismo lock, mismo estado ya
        // terminal) — no debe reintentar ni producir ningún efecto
        // adicional: la fila ya no es 'pending', así que la elegibilidad
        // (bajo lock, sobre datos frescos) la rechaza de inmediato, SIN
        // ninguna llamada de red nueva.
        Http::fake(); // cualquier llamada inesperada de aquí en más falla el test

        $second = $this->compensate($order->fresh());

        $this->assertSame('not_eligible', $second);
        Http::assertNothingSent();
        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertSame(0, CreditTransaction::count());
        $this->assertSame('failed', $order->fresh()->status);
    }

    // ---- D2. INTERLEAVING REAL (ronda de auditoría de seguridad financiera
    // final, sección 3/4 — no basta con llamadas secuenciales) ------------------
    //
    // Fuerza la interleaving exacta que pide el encargo: A ya ganó el claim
    // (compensateLostChallenge() nunca llama a cancelPayment() sin haberlo
    // ganado antes, ver claimForCompensation()) y está en el PRECISO
    // instante de invocar cancelPayment() — B intenta compensar el MISMO
    // intento en ese exacto momento, de forma reentrante (síncrona, mismo
    // proceso/conexión, disparada desde dentro del propio closure de
    // Http::fake() que responde el PUT de A) — no una segunda llamada
    // sencillamente posterior en el tiempo.

    public function test_true_interleaving_second_worker_cannot_claim_while_first_owns_an_active_claim(): void
    {
        [$order, , $profile] = $this->lostChallengeOrder();

        $putCalls = 0;
        $cancelled = false;
        $nestedOutcome = null;
        $id = $order->provider_order_id;

        Http::fake([
            "api.mercadopago.com/v1/payments/{$id}" => function ($request) use (&$putCalls, &$cancelled, &$nestedOutcome, $order, $id) {
                if ($request->method() === 'PUT') {
                    $putCalls++;

                    if ($putCalls === 1) {
                        // Worker A ya tiene el claim y está EXACTAMENTE
                        // aquí, a punto de llamar a cancelPayment() —
                        // worker B intenta lo mismo AHORA MISMO, de forma
                        // reentrante, antes de que A siquiera reciba
                        // respuesta a su propio PUT.
                        $nestedOutcome = app(MercadoPagoPaymentReconciliationService::class)
                            ->compensateLostChallenge($order->fresh(), app(MercadoPagoPaymentProvider::class));
                    }

                    $cancelled = true;

                    return Http::response(['id' => (int) $id, 'status' => 'cancelled'], 200);
                }

                $truth = $cancelled
                    ? ['status' => 'cancelled', 'status_detail' => 'by_collector']
                    : ['status' => 'pending', 'status_detail' => 'pending_challenge'];

                return Http::response($this->truthBody($order, $id) + $truth, 200);
            },
        ]);

        $outcomeA = $this->compensate($order);

        $this->assertSame('failed', $outcomeA);
        $this->assertSame('not_eligible', $nestedOutcome, 'B nunca debe poder reclamar mientras A tiene el claim activo');
        $this->assertSame(1, $putCalls, 'cancelPayment() se invoca como máximo una vez para el mismo intento, sin importar cuántos workers lo intenten a la vez');
        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertSame(0, CreditTransaction::count());
    }

    // ---- Crash recovery: claim persistido, worker muere antes del PUT/GET final ----

    public function test_crash_after_claim_is_eventually_recoverable_without_premature_retry(): void
    {
        [$order] = $this->lostChallengeOrder();

        // Simula el estado EXACTO que dejaría un worker muerto justo
        // después de ganar el claim (antes de siquiera llamar al PUT, o
        // antes de completar el GET final) — no hace falta inducir una
        // excepción real de red para probar la recuperación: el estado en
        // la fila es lo único que importa (mismo criterio que otros tests
        // de "boundary de fallo" de este proyecto, ver
        // MercadoPagoUncertainSubmissionRecoveryTest). El propio test C ya
        // cubre el camino donde SÍ se induce una excepción real de
        // transporte en el PUT/GET final.
        $order->update(['compensation_claimed_at' => now()]);
        $claimedAt = $order->fresh()->compensation_claimed_at;
        $this->assertNotNull($claimedAt);

        // Un worker posterior, CASI inmediatamente — el claim sigue fresco
        // (dentro de COMPENSATION_CLAIM_STALE_MINUTES) — NUNCA debe poder
        // reclamar de nuevo: "no premature retry". El GET canónico PREVIO
        // (paso 2 del algoritmo) SÍ corre igual — es solo lectura, seguro
        // de repetir — pero nunca llega a intentar la cancelación: el
        // claim (paso 4) lo rechaza.
        //
        // UNA SOLA llamada a Http::fake() para TODO el resto del test
        // (incluida la fase "recuperada" más abajo, tras avanzar el reloj)
        // — encontrado en vivo escribiendo este test: dos Http::fake()
        // sueltos para el MISMO patrón de URL no se reemplazan de forma
        // confiable (el primero registrado sigue respondiendo — ver
        // Illuminate\Http\Client\PendingRequest::buildStubHandler(), que
        // usa ->first() sobre la lista completa de stubs acumulados desde
        // el inicio del test), así que una fase "too soon" con su propio
        // Http::fake() y luego fakeCancellableFlow() en la fase
        // "recovered" haría que el PUT de la fase recuperada siguiera
        // viendo la respuesta ESTÁTICA de la fase "too soon" — el mismo
        // patrón ya documentado en
        // MercadoPagoUncertainSubmissionRecoveryTest::
        // test_review_audit_distinguishes_never_active_and_resolved().
        $this->fakeCancellableFlow($order->fresh());

        $tooSoon = $this->compensate($order->fresh());
        $this->assertSame('not_eligible', $tooSoon);
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
        $this->assertEquals($claimedAt, $order->fresh()->compensation_claimed_at, 'el claim no cambia en un intento prematuro');

        // Avanza el reloj más allá de la ventana de staleness — AHORA sí
        // debe poder recuperarse ("later recovery must eventually resume
        // safely") y completar la compensación con normalidad. Misma
        // Http::fake() de arriba sigue activa (nunca se reemplaza).
        \Illuminate\Support\Carbon::setTestNow(now()->addMinutes(6));

        $recovered = $this->compensate($order->fresh());

        $this->assertSame('failed', $recovered);
        $fresh = $order->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame('cancelled', $fresh->provider_status);
        $this->assertNull($fresh->compensation_claimed_at, 'el claim se libera al llegar a un desenlace terminal');

        \Illuminate\Support\Carbon::setTestNow();
    }

    // ---- CLAIM LIFECYCLE: un claim residual de un worker anterior se libera
    // en TODOS los puntos de salida donde compensateLostChallenge() sabe que
    // la compensación ya no aplica — no solo en el desenlace final (ronda de
    // auditoría de seguridad financiera final, segunda pasada, sección 2) --

    public function test_stale_claim_is_cleared_when_the_pre_check_get_already_shows_a_terminal_truth(): void
    {
        [$order, $recharge, $profile] = $this->lostChallengeOrder();

        // Simula un claim huérfano dejado por un worker anterior que murió
        // — el GET PREVIO de ESTA llamada resuelve directo a un terminal
        // (cancelled) sin necesidad de pasar por el claim/PUT de nuevo.
        $order->update(['compensation_claimed_at' => now()->subMinute()]);

        $id = $order->provider_order_id;
        Http::fake([
            "api.mercadopago.com/v1/payments/{$id}" => Http::response(
                $this->truthBody($order, $id) + ['status' => 'cancelled', 'status_detail' => 'by_collector'],
                200
            ),
        ]);

        $outcome = $this->compensate($order->fresh());

        $this->assertSame('failed', $outcome);
        $fresh = $order->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertNull($fresh->compensation_claimed_at, 'un claim residual no debe sobrevivir a un desenlace ya terminal en el GET previo');
        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertSame(0, CreditTransaction::count());
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT', 'el GET previo ya resolvió todo — nunca se intenta cancelar de nuevo');
    }

    public function test_stale_claim_is_cleared_when_the_pre_check_get_recovers_a_usable_challenge(): void
    {
        [$order] = $this->lostChallengeOrder(); // sin Challenge local (challengeUrl/creq null)

        // Mismo claim huérfano — pero esta vez el GET PREVIO trae un
        // Challenge recién recuperable (three_ds_info con datos válidos):
        // escenario C del encargo.
        $order->update(['compensation_claimed_at' => now()->subMinute()]);

        $id = $order->provider_order_id;
        Http::fake([
            "api.mercadopago.com/v1/payments/{$id}" => Http::response(
                $this->truthBody($order, $id) + [
                    'status' => 'pending',
                    'status_detail' => 'pending_challenge',
                    'three_ds_info' => [
                        'external_resource_url' => 'https://acs-public.tp.mastercard.com/api/v1/browser_Challenges',
                        'creq' => 'eyJmYWtlIjoicmVjb3ZlcmVkIn0=',
                    ],
                ],
                200
            ),
        ]);

        $outcome = $this->compensate($order->fresh());

        $this->assertSame('race_resolved', $outcome);
        $fresh = $order->fresh();
        $this->assertSame('pending', $fresh->status, 'el Challenge recién recuperado sigue pending, nunca se cancela');
        $this->assertSame('https://acs-public.tp.mastercard.com/api/v1/browser_Challenges', $fresh->three_ds_challenge_url, 'el Challenge recuperado sigue 100% intacto/usable');
        $this->assertSame('eyJmYWtlIjoicmVjb3ZlcmVkIn0=', $fresh->three_ds_creq);
        $this->assertNull($fresh->compensation_claimed_at, 'el claim residual se libera — solo se limpia el mutex interno, nunca el Challenge');
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT', 'nunca se cancela un Challenge que se acaba de volver utilizable');
    }

    // ---- CLAIM LIFECYCLE FUERA DE compensateLostChallenge() (ronda de
    // auditoría de seguridad financiera final, tercera pasada) ------------------
    //
    // Un worker de compensación puede reclamar (compensation_claimed_at) y
    // morir SIN volver a llamar a compensateLostChallenge(). Estos tests
    // invocan reconcile() DIRECTAMENTE — el mismo camino "ordinario" que ya
    // usan reconcileStuckOrders() (pasada 3 del barrido) y, en el futuro,
    // los webhooks — para probar que el claim se libera en el transition
    // point CORRECTO (applyPaid()/applyFailed()/recoverChallengeFieldsIfMissing())
    // sin importar QUIÉN disparó la reconciliación, nunca solo dentro de
    // compensateLostChallenge().

    public function test_external_reconcile_to_approved_clears_a_stale_claim_and_credits_exactly_once(): void
    {
        [$order, $recharge, $profile] = $this->lostChallengeOrder(credits: 5);
        $order->update(['compensation_claimed_at' => now()->subMinute()]); // claim huérfano de un worker muerto

        $id = $order->provider_order_id;
        Http::fake(['api.mercadopago.com/v1/payments/'.$id => Http::response(
            $this->truthBody($order, $id) + ['status' => 'approved', 'status_detail' => 'accredited'],
            200
        )]);

        $outcome = app(MercadoPagoPaymentReconciliationService::class)
            ->reconcile($order->fresh(), null, app(MercadoPagoPaymentProvider::class));

        $this->assertSame('paid', $outcome);
        $fresh = $order->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertNull($fresh->compensation_claimed_at, 'reconcile() ordinario debe liberar un claim huérfano al llegar a un terminal — no solo compensateLostChallenge()');
        $this->assertSame(5, $profile->fresh()->credits_available);
        $this->assertSame(1, CreditTransaction::where('idempotency_key', "recharge:{$recharge->id}:deposit")->count());
    }

    public function test_external_reconcile_to_a_terminal_rejection_clears_a_stale_claim_with_zero_credit(): void
    {
        [$order, , $profile] = $this->lostChallengeOrder();
        $order->update(['compensation_claimed_at' => now()->subMinute()]);

        $id = $order->provider_order_id;
        Http::fake(['api.mercadopago.com/v1/payments/'.$id => Http::response(
            $this->truthBody($order, $id) + ['status' => 'rejected', 'status_detail' => 'cc_rejected_other_reason'],
            200
        )]);

        $outcome = app(MercadoPagoPaymentReconciliationService::class)
            ->reconcile($order->fresh(), null, app(MercadoPagoPaymentProvider::class));

        $this->assertSame('failed', $outcome);
        $fresh = $order->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertNull($fresh->compensation_claimed_at, 'reconcile() ordinario debe liberar un claim huérfano al llegar a un terminal');
        $this->assertSame(0, $profile->fresh()->credits_available);
        $this->assertSame(0, CreditTransaction::count());
    }

    public function test_external_reconcile_recovering_an_actionable_challenge_clears_a_stale_claim_and_preserves_the_challenge(): void
    {
        [$order] = $this->lostChallengeOrder(); // sin Challenge local
        $order->update(['compensation_claimed_at' => now()->subMinute()]);

        $id = $order->provider_order_id;
        Http::fake(['api.mercadopago.com/v1/payments/'.$id => Http::response(
            $this->truthBody($order, $id) + [
                'status' => 'pending',
                'status_detail' => 'pending_challenge',
                'three_ds_info' => [
                    'external_resource_url' => 'https://acs-public.tp.mastercard.com/api/v1/browser_Challenges',
                    'creq' => 'eyJmYWtlIjoiZXh0ZXJuYWwifQ==',
                ],
            ],
            200
        )]);

        $outcome = app(MercadoPagoPaymentReconciliationService::class)
            ->reconcile($order->fresh(), null, app(MercadoPagoPaymentProvider::class));

        $this->assertSame('pending', $outcome);
        $fresh = $order->fresh();
        $this->assertSame('https://acs-public.tp.mastercard.com/api/v1/browser_Challenges', $fresh->three_ds_challenge_url, 'el Challenge recién recuperado queda intacto');
        $this->assertSame('eyJmYWtlIjoiZXh0ZXJuYWwifQ==', $fresh->three_ds_creq);
        $this->assertNotNull($fresh->three_ds_expires_at);
        $this->assertNull($fresh->compensation_claimed_at, 'reconcile() ordinario debe liberar el claim huérfano en cuanto recupera un Challenge utilizable');
    }

    /**
     * CONTROL NEGATIVO (crítico — sección 4 del encargo): una
     * reconciliación ordinaria que sigue en 'pending'/'pending_challenge'
     * SIN recuperar ningún Challenge NUNCA debe tocar un claim activo —
     * de lo contrario se recrearía exactamente el bug de concurrencia
     * original ("claim → GET sigue pending → claim borrado → un segundo
     * worker reclama", ver docblock de compensateLostChallenge()).
     */
    public function test_external_reconcile_still_pending_without_recovering_a_challenge_never_clears_an_active_claim(): void
    {
        [$order] = $this->lostChallengeOrder(); // sin Challenge local
        $order->update(['compensation_claimed_at' => now()->subMinute()]);
        $claimedAt = $order->fresh()->compensation_claimed_at;

        $id = $order->provider_order_id;
        Http::fake(['api.mercadopago.com/v1/payments/'.$id => Http::response(
            // pending/pending_challenge SIN three_ds_info — nada que recuperar.
            $this->truthBody($order, $id) + ['status' => 'pending', 'status_detail' => 'pending_challenge'],
            200
        )]);

        $outcome = app(MercadoPagoPaymentReconciliationService::class)
            ->reconcile($order->fresh(), null, app(MercadoPagoPaymentProvider::class));

        $this->assertSame('pending', $outcome);
        $fresh = $order->fresh();
        $this->assertNull($fresh->three_ds_challenge_url);
        $this->assertEquals($claimedAt, $fresh->compensation_claimed_at, 'un pending "vacío" NUNCA debe borrar un claim activo — reintroduciría la carrera original');
    }

    // ---- E. Challenge usable → NUNCA se intenta compensar -----------------------

    public function test_e_usable_active_challenge_is_never_compensated(): void
    {
        [$order] = $this->lostChallengeOrder(
            challengeUrl: 'https://acs-public.tp.mastercard.com/api/v1/browser_Challenges',
            creq: 'eyJmYWtlIjoiY3JlcSJ9',
            challengeExpiresAt: now()->addMinutes(3), // todavía vigente
        );

        $this->assertFalse(
            app(MercadoPagoPaymentReconciliationService::class)->isLostChallengeCompensationCandidate($order->fresh())
        );

        Http::fake();
        $outcome = $this->compensate($order);

        $this->assertSame('not_eligible', $outcome);
        Http::assertNothingSent();
        $this->assertNotNull($order->fresh()->three_ds_challenge_url, 'un Challenge normal/usable nunca se toca');
    }

    // ---- F. Datos perdidos por sí solos, SIN presupuesto agotado ----------------

    public function test_f_missing_challenge_data_alone_is_not_enough_before_the_recovery_threshold(): void
    {
        // Recién atascado (updated_at reciente) — nunca se compensa solo
        // porque el Challenge esté vacío; hace falta que el presupuesto de
        // recuperación normal (stuck_order_minutes) se haya agotado.
        [$recentOrder] = $this->lostChallengeOrder(stuckMinutesAgo: 1);
        $this->assertFalse(
            app(MercadoPagoPaymentReconciliationService::class)->isLostChallengeCompensationCandidate($recentOrder->fresh())
        );

        // Atascado el tiempo suficiente, pero JAMÁS verificado
        // server-to-server (last_verified_at null) — tampoco alcanza: no
        // hubo ni una sola oportunidad real de que
        // recoverChallengeFieldsIfMissing() intentara reconstruirlo.
        [$neverVerified] = $this->lostChallengeOrder(stuckMinutesAgo: 45, verified: false);
        $this->assertFalse(
            app(MercadoPagoPaymentReconciliationService::class)->isLostChallengeCompensationCandidate($neverVerified->fresh())
        );

        Http::fake();
        $this->assertSame('not_eligible', $this->compensate($recentOrder));
        $this->assertSame('not_eligible', $this->compensate($neverVerified));
        Http::assertNothingSent();

        // Control positivo: mismas condiciones, pero atascado Y ya
        // verificado al menos una vez — SÍ es candidato.
        [$eligible] = $this->lostChallengeOrder(stuckMinutesAgo: 45, verified: true);
        $this->assertTrue(
            app(MercadoPagoPaymentReconciliationService::class)->isLostChallengeCompensationCandidate($eligible->fresh())
        );
    }

    // ---- Protección adicional: un 'pending' normal (no Challenge) nunca califica ----

    public function test_normal_pending_payment_without_a_challenge_is_never_a_candidate(): void
    {
        [$order] = $this->lostChallengeOrder(stuckMinutesAgo: 45, verified: true);
        $order->update(['provider_status_detail' => null]); // pending genérico, nunca fue un Challenge

        $this->assertFalse(
            app(MercadoPagoPaymentReconciliationService::class)->isLostChallengeCompensationCandidate($order->fresh())
        );
    }

    // ---- helpers ------------------------------------------------------------------

    private function compensate(PaymentOrder $order): string
    {
        return app(MercadoPagoPaymentReconciliationService::class)->compensateLostChallenge(
            $order,
            app(MercadoPagoPaymentProvider::class)
        );
    }

    /**
     * @return array{transaction_amount:float,transaction_amount_refunded:int,external_reference:string,currency_id:string,id:int}
     */
    private function truthBody(PaymentOrder $order, string $id): array
    {
        return [
            'id' => (int) $id,
            'transaction_amount' => (float) Money::minorToSoles($order->amount_minor),
            'transaction_amount_refunded' => 0,
            'external_reference' => $order->externalReference(),
            'currency_id' => $order->currency,
        ];
    }

    /**
     * Fake robusto a CUALQUIER número de GET previos (p.ej. si el barrido
     * de "stuck orders" ya tocó la misma fila antes) — antes del PUT
     * siempre responde pending/pending_challenge; desde el PUT en
     * adelante, siempre cancelled. Nunca depende de contar llamadas.
     */
    private function fakeCancellableFlow(PaymentOrder $order): void
    {
        $id = $order->provider_order_id;
        $cancelled = false;

        Http::fake([
            "api.mercadopago.com/v1/payments/{$id}" => function ($request) use (&$cancelled, $order, $id) {
                if ($request->method() === 'PUT') {
                    $cancelled = true;

                    return Http::response(['id' => (int) $id, 'status' => 'cancelled'], 200);
                }

                $truth = $cancelled
                    ? ['status' => 'cancelled', 'status_detail' => 'by_collector']
                    : ['status' => 'pending', 'status_detail' => 'pending_challenge'];

                return Http::response($this->truthBody($order, $id) + $truth, 200);
            },
        ]);
    }

    /**
     * @return array{0:PaymentOrder,1:RechargeRequest,2:TeacherProfile}
     */
    private function lostChallengeOrder(
        string $amountPen = '10.00',
        int $credits = 5,
        ?string $challengeUrl = null,
        ?string $creq = null,
        ?\Illuminate\Support\Carbon $challengeExpiresAt = null,
        bool $verified = true,
        int $stuckMinutesAgo = 45,
    ): array {
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
            'idempotency_key' => (string) Str::uuid(),
            'provider' => 'mercadopago',
            'provider_order_id' => (string) fake()->unique()->numberBetween(100000, 999999),
            'status' => 'pending',
            'submission_status' => 'submitted',
            'provider_status' => 'pending',
            'provider_status_detail' => 'pending_challenge',
            'three_ds_challenge_url' => $challengeUrl,
            'three_ds_creq' => $creq,
            'three_ds_expires_at' => $challengeExpiresAt,
            'amount_minor' => Money::solesToMinor($amountPen),
            'currency' => 'PEN',
            'last_verified_at' => $verified ? now()->subMinutes($stuckMinutesAgo) : null,
        ]);

        // created_at (NUNCA updated_at — ver docblock de
        // isLostChallengeCompensationCandidate() sobre por qué— manipulado
        // directamente (bypass de timestamps automáticos de Eloquent) para
        // simular un intento genuinamente viejo, sin depender de
        // Carbon::setTestNow() global.
        DB::table('payment_orders')->where('id', $order->id)->update([
            'created_at' => now()->subMinutes($stuckMinutesAgo),
        ]);

        return [$order->fresh(), $recharge, $profile];
    }
}
