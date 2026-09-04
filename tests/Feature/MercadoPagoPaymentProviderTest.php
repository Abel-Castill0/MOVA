<?php

namespace Tests\Feature;

use App\Models\PaymentOrder;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Payment\Contracts\CardPaymentInstrument;
use App\Payment\Contracts\YapePaymentInstrument;
use App\Payment\MercadoPago\MalformedWebhookPayloadException;
use App\Payment\MercadoPagoPaymentProvider;
use App\Services\MercadoPagoPaymentReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Prueba MercadoPagoPaymentProvider contra la forma real documentada de
 * Payments API (`POST/GET /v1/payments` — pivot desde Orders API, ver
 * decisión de la sesión: Yape no está documentado oficialmente sobre
 * Orders API), sin tocar red real (Http::fake()). El "access token" de
 * estos tests es un placeholder de laboratorio sin forma de credencial
 * real (nunca 'TEST-...'/'APP_USR-...').
 */
class MercadoPagoPaymentProviderTest extends TestCase
{
    use RefreshDatabase;

    private const LAB_ACCESS_TOKEN = 'unit-test-placeholder-000';

    protected function setUp(): void
    {
        parent::setUp();

        // TEST ISOLATION (MOVA Yape Final Pre-Card Gate): estos 4 valores son
        // los únicos que verifyWebhook() lee condicionalmente (`if
        // ($expected... && $notification... !== null)`) — si no se fuerzan
        // aquí, un desarrollador con MERCADOPAGO_APPLICATION_ID/
        // MERCADOPAGO_EXPECTED_COLLECTOR_ID/MERCADOPAGO_EXPECTED_LIVE_MODE
        // reales en su .env local hace que el resultado del test dependa de
        // su máquina en vez del payload declarado (bug real encontrado:
        // test_verify_webhook_accepts_a_valid_payment_notification_without_application_id_field
        // fallaba solo cuando el .env local tenía un expected_collector_id
        // real que no coincidía con el user_id ficticio del payload). Los
        // tests que necesitan un valor concreto lo sobrescriben ellos mismos
        // vía config() más abajo — este bloque solo fija el resto a un
        // estado neutro (ningún chequeo condicional se dispara).
        config([
            'payments.mercadopago.base_url' => 'https://api.mercadopago.com',
            'payments.mercadopago.access_token' => self::LAB_ACCESS_TOKEN,
            'payments.mercadopago.webhook_secret' => 'unit-test-webhook-secret',
            'payments.mercadopago.application_id' => null,
            'payments.mercadopago.expected_collector_id' => null,
            'payments.mercadopago.expected_live_mode' => null,
            'payments.mercadopago.webhooks_enabled' => false,
        ]);
    }

    // ---- createPaymentAttempt() — tarjeta -----------------------------------

    public function test_creates_a_card_payment_with_the_documented_payments_api_payload(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response([
            'id' => 74581527758,
            'status' => 'approved',
            'status_detail' => 'accredited',
        ], 201)]);

        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile, amountPen: '10.00', credits: 5);

        $order = (new MercadoPagoPaymentProvider())->createPaymentAttempt($recharge, $this->cardInstrument());

        $this->assertSame($recharge->id, $order->recharge_request_id);
        $this->assertSame(1, $order->attempt_number);
        $this->assertSame('mercadopago', $order->provider);
        $this->assertSame('74581527758', $order->provider_order_id);
        // NUNCA 'paid' aquí aunque Mercado Pago ya respondió
        // approved/accredited de forma síncrona — ver docblock de la clase:
        // solo la reconciliación server-to-server acredita.
        $this->assertSame('pending', $order->status);
        $this->assertSame(1000, $order->amount_minor);
        $this->assertSame('PEN', $order->currency);

        // La X-Idempotency-Key ahora se GENERA y PERSISTE (UUID v4) al
        // crear la fila (ver resolveAttemptRow()) — ya no se deriva de
        // "recharge-{id}-attempt-{n}" en cada llamada. Se verifica que la
        // key enviada sea EXACTAMENTE la persistida en payment_orders, con
        // forma de UUID v4 real.
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $order->idempotency_key
        );

        $expectedAuthHeader = 'Bearer '.self::LAB_ACCESS_TOKEN;
        Http::assertSent(function ($request) use ($recharge, $order, $expectedAuthHeader) {
            return $request->url() === 'https://api.mercadopago.com/v1/payments'
                && $request->hasHeader('Authorization', $expectedAuthHeader)
                && $request->hasHeader('X-Idempotency-Key', $order->idempotency_key)
                // NÚMERO, no string — a diferencia de Orders API.
                && $request['transaction_amount'] === 10.0
                && $request['external_reference'] === "recharge:{$recharge->id}:attempt:1"
                && $request['payment_method_id'] === 'visa'
                && $request['token'] === 'card-token-abc'
                && $request['installments'] === 1
                && $request['payer']['email'] === $recharge->teacherProfile->user->email
                && ! isset($request['payer']['identification']);
        });
    }

    public function test_card_payload_includes_issuer_id_and_identification_when_provided(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response(['id' => 1, 'status' => 'pending', 'status_detail' => 'pending_contingency'], 201)]);

        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        (new MercadoPagoPaymentProvider())->createPaymentAttempt($recharge, new CardPaymentInstrument(
            token: 'card-token-abc',
            paymentMethodId: 'visa',
            installments: 3,
            issuerId: '310',
            identificationType: 'DNI',
            identificationNumber: '12345678',
        ));

        Http::assertSent(function ($request) {
            return $request['issuer_id'] === '310'
                && $request['installments'] === 3
                && $request['payer']['identification']['type'] === 'DNI'
                && $request['payer']['identification']['number'] === '12345678';
        });
    }

    public function test_payer_email_is_always_the_authenticated_teacher_never_from_the_instrument(): void
    {
        // TokenizedPaymentInstrument no tiene NINGÚN campo de email a
        // propósito (sección 6 del encargo) — este test documenta que no
        // hay forma de que exista uno que el provider pudiera confiar.
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response(['id' => 1, 'status' => 'pending', 'status_detail' => 'x'], 201)]);

        [$teacher, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        (new MercadoPagoPaymentProvider())->createPaymentAttempt($recharge, $this->cardInstrument());

        Http::assertSent(fn ($request) => $request['payer']['email'] === $teacher->email);
    }

    // ---- createPaymentAttempt() — Yape ---------------------------------------

    public function test_yape_payload_forces_payment_method_id_and_installments_server_side(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response([
            'id' => 200,
            'status' => 'approved',
            'status_detail' => 'accredited',
        ], 201)]);

        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile, amountPen: '25.00');

        $order = (new MercadoPagoPaymentProvider())->createPaymentAttempt($recharge, new YapePaymentInstrument(token: 'yape-token-xyz'));

        $this->assertSame('pending', $order->status);

        Http::assertSent(function ($request) {
            return $request['payment_method_id'] === 'yape'
                && $request['installments'] === 1
                && $request['token'] === 'yape-token-xyz'
                && $request['transaction_amount'] === 25.0
                // Yape ya tiene su propio mecanismo de autenticación (OTP) —
                // Payments API no documenta three_d_secure_mode para este
                // medio de pago, así que MOVA nunca debe enviarlo aquí.
                && ! isset($request['three_d_secure_mode']);
        });
    }

    // ---- 3DS Challenge (MOVA Card Payment Brick 3DS) ------------------------

    public function test_card_payload_enables_3ds_in_optional_mode(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response([
            'id' => 400,
            'status' => 'approved',
            'status_detail' => 'accredited',
        ], 201)]);

        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        (new MercadoPagoPaymentProvider())->createPaymentAttempt($recharge, $this->cardInstrument());

        Http::assertSent(fn ($request) => $request['three_d_secure_mode'] === 'optional');
    }

    /**
     * CARD PAYLOAD CONTRACT (ronda de hardening final): capture=true y
     * binary_mode=false explícitos — CRÍTICO para binary_mode, ver docblock
     * de createPaymentAttempt(): activado, Mercado Pago rechazaría de
     * inmediato cualquier pago que de otro modo quedaría pending, incluido
     * un Challenge 3DS. Se verifica para AMBOS medios de pago (no es
     * exclusivo de 3DS/tarjeta como three_d_secure_mode).
     */
    public function test_card_payload_always_sends_capture_true_and_binary_mode_false(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response(['id' => 1, 'status' => 'pending', 'status_detail' => 'x'], 201)]);

        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);
        (new MercadoPagoPaymentProvider())->createPaymentAttempt($recharge, $this->cardInstrument());

        Http::assertSent(fn ($request) => $request['capture'] === true && $request['binary_mode'] === false);
    }

    public function test_yape_payload_always_sends_capture_true_and_binary_mode_false(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response(['id' => 1, 'status' => 'pending', 'status_detail' => 'x'], 201)]);

        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);
        (new MercadoPagoPaymentProvider())->createPaymentAttempt($recharge, new YapePaymentInstrument(token: 'yape-token-cap'));

        Http::assertSent(fn ($request) => $request['capture'] === true && $request['binary_mode'] === false);
    }

    /**
     * El caso central de esta ronda: una respuesta síncrona 'pending_challenge'
     * NUNCA acredita (sigue cayendo en la misma rama $localStatus='pending' de
     * siempre) y además persiste los dos datos que el frontend necesita para
     * dibujar el iframe del banco — ver
     * MercadoPagoPaymentProvider::extractChallengeFields().
     */
    public function test_pending_challenge_response_stores_challenge_fields_without_crediting(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response([
            'id' => 401,
            'status' => 'pending',
            'status_detail' => 'pending_challenge',
            'three_ds_info' => [
                'external_resource_url' => 'https://acs-public.tp.mastercard.com/api/v1/browser_Challenges',
                'creq' => 'eyJ0aHJlZURTU2VydmVyVHJhbnNJRCI6ImJmYTVhZjI0In0',
            ],
        ], 201)]);

        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        $order = (new MercadoPagoPaymentProvider())->createPaymentAttempt($recharge, $this->cardInstrument());

        $this->assertSame('pending', $order->status);
        $this->assertSame('submitted', $order->submission_status);
        $this->assertSame('pending_challenge', $order->provider_status_detail);
        $this->assertSame('https://acs-public.tp.mastercard.com/api/v1/browser_Challenges', $order->three_ds_challenge_url);
        $this->assertSame('eyJ0aHJlZURTU2VydmVyVHJhbnNJRCI6ImJmYTVhZjI0In0', $order->three_ds_creq);
        $this->assertSame(0, $recharge->fresh()->teacherProfile->credits_available, 'un status_detail pending_challenge nunca debe acreditar créditos');
    }

    public function test_non_challenge_response_never_sets_challenge_fields(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response([
            'id' => 402,
            'status' => 'approved',
            'status_detail' => 'accredited',
        ], 201)]);

        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        $order = (new MercadoPagoPaymentProvider())->createPaymentAttempt($recharge, $this->cardInstrument());

        $this->assertNull($order->three_ds_challenge_url);
        $this->assertNull($order->three_ds_creq);
    }

    public function test_malformed_three_ds_info_is_ignored_and_still_stays_pending(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response([
            'id' => 403,
            'status' => 'pending',
            'status_detail' => 'pending_challenge',
            'three_ds_info' => ['external_resource_url' => '', 'creq' => null],
        ], 201)]);

        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        $order = (new MercadoPagoPaymentProvider())->createPaymentAttempt($recharge, $this->cardInstrument());

        $this->assertSame('pending', $order->status);
        $this->assertNull($order->three_ds_challenge_url);
        $this->assertNull($order->three_ds_creq);
        // Sin URL/creq utilizables no hay Challenge que temporizar.
        $this->assertNull($order->three_ds_expires_at);
    }

    /**
     * CHALLENGE URL SAFETY (ronda de hardening final): un
     * external_resource_url que no sea HTTPS válida (scheme distinto,
     * javascript:/data:/file:, o sin host) nunca se persiste — el intento
     * sigue 'pending' (sin riesgo financiero nuevo), pero
     * three_ds_challenge_url queda null, así que
     * CreditCheckoutController::safeStatus() nunca expondrá action_required
     * para este intento.
     *
     * @dataProvider unsafeChallengeUrls
     */
    public function test_unsafe_external_resource_url_is_discarded_and_never_persisted(string $unsafeUrl): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response([
            'id' => 404,
            'status' => 'pending',
            'status_detail' => 'pending_challenge',
            'three_ds_info' => ['external_resource_url' => $unsafeUrl, 'creq' => 'valid-creq-token'],
        ], 201)]);

        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        $order = (new MercadoPagoPaymentProvider())->createPaymentAttempt($recharge, $this->cardInstrument());

        $this->assertSame('pending', $order->status);
        $this->assertNull($order->three_ds_challenge_url);
        $this->assertNull($order->three_ds_expires_at);
    }

    public static function unsafeChallengeUrls(): array
    {
        return [
            'javascript scheme' => ['javascript:alert(1)'],
            'data scheme' => ['data:text/html,<script>alert(1)</script>'],
            'file scheme' => ['file:///etc/passwd'],
            'plain http, no TLS' => ['http://acs-public.tp.mastercard.com/challenge'],
            'sin scheme ni host' => ['not-a-url'],
        ];
    }

    /**
     * CHALLENGE TIMEOUT (ronda de hardening final): un Challenge utilizable
     * (URL HTTPS válida + creq) fija `three_ds_expires_at` ~5 minutos
     * adelante — columna DEDICADA, nunca `payment_orders.expires_at` (ver
     * docblock de createPaymentAttempt(), sección CHALLENGE TIMEOUT).
     */
    public function test_valid_challenge_sets_an_expires_at_about_five_minutes_ahead(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response([
            'id' => 405,
            'status' => 'pending',
            'status_detail' => 'pending_challenge',
            'three_ds_info' => [
                'external_resource_url' => 'https://acs-public.tp.mastercard.com/api/v1/browser_Challenges',
                'creq' => 'valid-creq-token',
            ],
        ], 201)]);

        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        $before = now();
        $order = (new MercadoPagoPaymentProvider())->createPaymentAttempt($recharge, $this->cardInstrument());

        $this->assertNotNull($order->three_ds_expires_at);
        $this->assertTrue($order->three_ds_expires_at->between($before->clone()->addMinutes(4), $before->clone()->addMinutes(6)));
    }

    /**
     * CHALLENGE EXPIRY MUST NEVER SLIDE (ronda de semántica final):
     * `three_ds_expires_at` se fija UNA sola vez, en createPaymentAttempt().
     * Una reconciliación posterior mientras el Challenge sigue sin resolverse
     * (Mercado Pago sigue reportando 'pending'/'pending_challenge') NUNCA
     * debe reiniciar la ventana de 5 minutos — ver docblock de
     * createPaymentAttempt(), sección "NUNCA SE DESLIZA".
     */
    public function test_challenge_expiry_never_slides_across_repeated_reconciliation(): void
    {
        Carbon::setTestNow('2026-09-03 10:00:00');

        Http::fake(['api.mercadopago.com/v1/payments' => Http::response([
            'id' => 500,
            'status' => 'pending',
            'status_detail' => 'pending_challenge',
            'three_ds_info' => [
                'external_resource_url' => 'https://acs-public.tp.mastercard.com/api/v1/browser_Challenges',
                'creq' => 'eyJmYWtlIjoiY3JlcSJ9',
            ],
        ], 201)]);

        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile, amountPen: '10.00');

        $order = (new MercadoPagoPaymentProvider())->createPaymentAttempt($recharge, $this->cardInstrument());
        $originalExpiry = $order->three_ds_expires_at;
        $this->assertSame('2026-09-03 10:05:00', $originalExpiry->toDateTimeString());

        // El profesor sigue en la pantalla, el polling de refresh() reconcilia
        // repetidamente mientras el Challenge sigue abierto — Mercado Pago
        // TODAVÍA reporta 'pending'/'pending_challenge' (no resuelto).
        Carbon::setTestNow('2026-09-03 10:02:00');
        Http::fake(['api.mercadopago.com/v1/payments/500' => Http::response([
            'id' => 500,
            'status' => 'pending',
            'status_detail' => 'pending_challenge',
            'transaction_amount' => 10.0,
            'transaction_amount_refunded' => 0,
            'currency_id' => 'PEN',
            'external_reference' => $order->externalReference(),
            'collector_id' => null,
        ], 200)]);

        $outcome = app(MercadoPagoPaymentReconciliationService::class)
            ->reconcile($order->fresh(), null, new MercadoPagoPaymentProvider());

        $this->assertSame('pending', $outcome);
        $this->assertSame($originalExpiry->toDateTimeString(), $order->fresh()->three_ds_expires_at->toDateTimeString());

        // Segunda ronda de reconciliación, más tarde todavía, mismo resultado.
        Carbon::setTestNow('2026-09-03 10:04:30');
        app(MercadoPagoPaymentReconciliationService::class)
            ->reconcile($order->fresh(), null, new MercadoPagoPaymentProvider());

        $this->assertSame($originalExpiry->toDateTimeString(), $order->fresh()->three_ds_expires_at->toDateTimeString());
        // Los datos del iframe tampoco se tocan mientras sigue sin resolverse.
        $this->assertSame('eyJmYWtlIjoiY3JlcSJ9', $order->fresh()->three_ds_creq);

        Carbon::setTestNow();
    }

    // ---- resultado síncrono negativo se refleja, positivo nunca ------------

    public function test_synchronous_rejected_response_is_reflected_locally_without_crediting(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response([
            'id' => 300,
            'status' => 'rejected',
            'status_detail' => 'cc_rejected_other_reason',
        ], 201)]);

        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        $order = (new MercadoPagoPaymentProvider())->createPaymentAttempt($recharge, $this->cardInstrument());

        $this->assertSame('failed', $order->status);
        // PRESERVA: un payment status=rejected es un recurso VÁLIDO (2xx
        // con id) — failure normal del payment vía PaymentStatusMapper,
        // totalmente distinto de un HTTP integration error. submission_status
        // es 'submitted' (id conocido), nunca 'uncertain'/null.
        $this->assertSame('submitted', $order->submission_status);
        $this->assertSame('300', $order->provider_order_id);
    }

    public function test_synchronous_pending_response_stays_pending_locally(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response([
            'id' => 301,
            'status' => 'in_process',
            'status_detail' => 'pending_review_manual',
        ], 201)]);

        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        $order = (new MercadoPagoPaymentProvider())->createPaymentAttempt($recharge, $this->cardInstrument());

        $this->assertSame('pending', $order->status);
    }

    // ---- sin instrumento: fail closed ---------------------------------------

    public function test_throws_without_creating_an_order_when_no_instrument_given(): void
    {
        Http::fake();

        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        $this->expectException(RuntimeException::class);

        try {
            (new MercadoPagoPaymentProvider())->createPaymentAttempt($recharge);
        } finally {
            Http::assertNothingSent();
            $this->assertDatabaseCount('payment_orders', 0);
        }
    }

    // ---- ATTEMPT CONCURRENCY / NETWORK TIMEOUT SEMANTICS --------------------

    /**
     * NETWORK TIMEOUT SEMANTICS + TOKEN/IDEMPOTENCY INVARIANT (ronda de
     * hardening distribuido): el POST se pierde por una excepción de red —
     * la fila local queda 'pending'/submission_status='uncertain' con
     * provider_order_id NULL. A diferencia de la ronda anterior, un
     * reintento YA NO reenvía directamente un POST bajo la misma key: el
     * gate de createPaymentAttempt() SIEMPRE fuerza primero la recuperación
     * por external_reference (nunca un segundo cobro automático). Si esa
     * búsqueda encuentra que Mercado Pago SÍ creó el pago, se reconcilia por
     * el camino normal (misma fila, misma key) — nunca una fila nueva.
     */
    public function test_a_retry_after_a_connection_exception_resolves_via_search_reusing_the_same_row_and_key(): void
    {
        // La recarga se crea ANTES de armar el fake para poder derivar el
        // external_reference real (PaymentOrder::externalReference():
        // "recharge:{id}:attempt:{n}") en vez de asumir recharge_request_id=1.
        // Ese id lo asigna el motor de base de datos (AUTO_INCREMENT en
        // MySQL, rowid en SQLite): bajo SQLite :memory: siempre da 1 porque
        // cada test arranca desde una base vacía, pero bajo MySQL/InnoDB el
        // contador de auto-incremento NUNCA retrocede aunque la transacción
        // de un test anterior se revierta (RefreshDatabase hace rollback de
        // los datos, no del contador) — así que asumir "1" aquí es seguro
        // solo por accidente bajo SQLite y falla en cuanto corre después de
        // cualquier otro test bajo mysql_qa. Este es el mismo attempt_number
        // que resolveAttemptRow() le asignará (primer intento de una recarga
        // nueva → 1), verificado más abajo contra $resolved->attempt_number.
        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);
        $externalReference = "recharge:{$recharge->id}:attempt:1";

        Http::fake([
            'api.mercadopago.com/v1/payments' => function () {
                throw new ConnectionException('cURL error 28: Operation timed out');
            },
            'api.mercadopago.com/v1/payments/search*' => Http::response([
                'paging' => ['total' => 1, 'limit' => 10, 'offset' => 0],
                'results' => [[
                    'id' => 555,
                    'status' => 'approved',
                    'status_detail' => 'accredited',
                    'transaction_amount' => 10.0,
                    'transaction_amount_refunded' => 0,
                    'currency_id' => 'PEN',
                    'external_reference' => $externalReference,
                    'collector_id' => null,
                ]],
            ], 200),
            // reconcileUncertainSubmission() persiste el id encontrado y
            // reutiliza reconcile() — que vuelve a leer la verdad vía
            // fetchPayment() (GET /v1/payments/{id}), nunca confía en el
            // resultado de la búsqueda directamente.
            'api.mercadopago.com/v1/payments/555' => Http::response([
                'id' => 555,
                'status' => 'approved',
                'status_detail' => 'accredited',
                'transaction_amount' => 10.0,
                'transaction_amount_refunded' => 0,
                'currency_id' => 'PEN',
                'external_reference' => $externalReference,
                'collector_id' => null,
            ], 200),
        ]);

        $provider = new MercadoPagoPaymentProvider();

        try {
            $provider->createPaymentAttempt($recharge, $this->cardInstrument());
            $this->fail('Se esperaba RuntimeException (excepción de red envuelta).');
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertDatabaseCount('payment_orders', 1);
        $pending = PaymentOrder::sole();
        $this->assertSame('pending', $pending->status);
        $this->assertSame('uncertain', $pending->submission_status);
        $this->assertNull($pending->provider_order_id);
        $this->assertNotNull($pending->idempotency_key);
        $firstKey = $pending->idempotency_key;

        // El reintento del profesor: el gate dispara la búsqueda por
        // external_reference ANTES de siquiera considerar un POST nuevo —
        // este método SIEMPRE lanza cuando parte de un estado incierto.
        try {
            $provider->createPaymentAttempt($recharge, $this->cardInstrument());
            $this->fail('Se esperaba RuntimeException (gate de intento incierto).');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('resultado incierto', $e->getMessage());
        }

        $this->assertDatabaseCount('payment_orders', 1); // NUNCA una segunda fila
        $resolved = PaymentOrder::sole();
        $this->assertSame(1, $resolved->attempt_number);
        $this->assertSame($firstKey, $resolved->idempotency_key);
        $this->assertSame('555', $resolved->provider_order_id);
        $this->assertSame('submitted', $resolved->submission_status);
        // La búsqueda resolvió que SÍ se creó y aprobó — reconcile() corrió
        // por el camino normal y acreditó (no una lógica de crédito propia).
        $this->assertSame('paid', $resolved->status);

        // Nunca un segundo POST bajo la misma key ni ninguna key — el
        // reintento solo disparó la búsqueda dirigida (+ el fetchPayment()
        // normal que reconcile() ya hace siempre). Http::recorded() no
        // registra el primer POST porque el fake lanzó ANTES de producir
        // una respuesta — cero coincidencias aquí confirma que no hubo
        // ningún POST adicional (el único POST real de la prueba es el que
        // ya se verificó arriba que lanzó la excepción).
        $postAttempts = collect(Http::recorded())
            ->filter(fn ($pair) => $pair[0]->url() === 'https://api.mercadopago.com/v1/payments');
        $this->assertCount(0, $postAttempts);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/payments/search')
            && $request['external_reference'] === $resolved->externalReference());
    }

    /**
     * Una respuesta HTTP DEFINITIVA de error (no una excepción de red) sí
     * deja el intento en 'failed' — MOVA tiene certeza de que Mercado Pago
     * no creó ningún recurso. Eso permite que un intento NUEVO (attempt 2)
     * sea posible después, con una key distinta.
     */
    public function test_a_new_attempt_after_a_definitive_http_rejection_gets_a_new_idempotency_key_and_attempt_number(): void
    {
        // PROVIDER ERROR TAXONOMY: 'property_value' es uno de los códigos
        // 400 confirmados como request/input terminal (ver
        // MercadoPagoPaymentProvider::TERMINAL_400_CODES) — sin un código
        // reconocido, este 400 se clasificaría 'unclassified' (incierto),
        // no terminal.
        Http::fake(['api.mercadopago.com/v1/payments' => Http::sequence()
            ->push(['error' => 'property_value', 'message' => 'invalid transaction_amount'], 400)
            ->push(['id' => 2, 'status' => 'approved', 'status_detail' => 'accredited'], 201)]);

        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);
        $provider = new MercadoPagoPaymentProvider();

        try {
            $provider->createPaymentAttempt($recharge, $this->cardInstrument());
            $this->fail('Se esperaba RuntimeException.');
        } catch (RuntimeException) {
            // esperado — HTTP 400 definitivo
        }

        $first = PaymentOrder::sole();
        $this->assertSame(1, $first->attempt_number);
        $this->assertSame('failed', $first->status);
        $firstKey = $first->idempotency_key;

        $second = $provider->createPaymentAttempt($recharge, new YapePaymentInstrument(token: 'yape-token-retry'));
        $this->assertSame(2, $second->attempt_number);
        $this->assertSame('pending', $second->status);
        $this->assertNotSame($firstKey, $second->idempotency_key);

        $this->assertDatabaseCount('payment_orders', 2);
    }

    /**
     * TOKEN + IDEMPOTENCY INVARIANT: un intento incierto seguido de un
     * request con un token de un medio de pago DISTINTO (Yape en vez de
     * tarjeta) NUNCA reenvía ese payload nuevo bajo la misma
     * X-Idempotency-Key — el gate de createPaymentAttempt() no distingue
     * "mismo token" de "token nuevo" por identidad (nunca se persiste un
     * token para poder comparar); trata CUALQUIER intento previo incierto
     * igual: primero recuperación, nunca un POST directo. Aquí la búsqueda
     * NO encuentra nada (0 resultados, dentro del budget) — demuestra que
     * ni siquiera se llega a construir/enviar el payload del nuevo medio de
     * pago.
     */
    public function test_a_different_payment_method_on_retry_never_silently_reuses_the_uncertain_key(): void
    {
        Http::fake([
            'api.mercadopago.com/v1/payments' => function () {
                throw new ConnectionException('cURL error 28: Operation timed out');
            },
            'api.mercadopago.com/v1/payments/search*' => Http::response(
                ['paging' => ['total' => 0, 'limit' => 10, 'offset' => 0], 'results' => []],
                200
            ),
        ]);

        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);
        $provider = new MercadoPagoPaymentProvider();

        // Primer intento: tarjeta, se pierde por red.
        try {
            $provider->createPaymentAttempt($recharge, $this->cardInstrument());
            $this->fail('Se esperaba RuntimeException.');
        } catch (RuntimeException) {
            // esperado
        }

        $uncertain = PaymentOrder::sole();
        $this->assertSame('uncertain', $uncertain->submission_status);
        $firstKey = $uncertain->idempotency_key;

        // El profesor cambia de medio de pago (Yape) para el reintento —
        // NUNCA debe terminar en un POST con el payload de Yape bajo la
        // key de la tarjeta.
        try {
            $provider->createPaymentAttempt($recharge, new YapePaymentInstrument(token: 'yape-token-different'));
            $this->fail('Se esperaba RuntimeException (gate de intento incierto).');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('resultado incierto', $e->getMessage());
        }

        // Sigue habiendo una sola fila, con la MISMA key original — la
        // búsqueda no encontró nada, así que sigue incierta (dentro del
        // budget), nunca se creó una fila/attempt nueva para Yape.
        $this->assertDatabaseCount('payment_orders', 1);
        $stillUncertain = PaymentOrder::sole();
        $this->assertSame($firstKey, $stillUncertain->idempotency_key);
        $this->assertSame(1, $stillUncertain->attempt_number);
        $this->assertSame('uncertain', $stillUncertain->submission_status);
        $this->assertSame(1, $stillUncertain->recovery_attempts);

        // Nunca un segundo POST — ni con el payload de tarjeta ni con el de
        // Yape.
        $postAttempts = collect(Http::recorded())
            ->filter(fn ($pair) => $pair[0]->url() === 'https://api.mercadopago.com/v1/payments');
        $this->assertCount(0, $postAttempts);
    }

    // ---- SUBMISSION LIFECYCLE / PRE-SUBMISSION CRASH BOUNDARY ---------------

    /**
     * FAILURE BOUNDARY #1: DB crea PaymentOrder attempt + UUID → COMMIT →
     * proceso muere ANTES de iniciar el POST. La fila queda
     * submission_status='prepared' (simulada directamente aquí — es
     * exactamente el estado que resolveAttemptRow() deja tras crear la
     * fila, antes de que createPaymentAttempt() llegue a la marca
     * 'submitting'). La SIGUIENTE request reutiliza el MISMO attempt/key,
     * puede traer un token NUEVO (nada se envió nunca — cero riesgo), y
     * termina en un solo POST.
     */
    public function test_attempt_committed_but_post_never_started_stays_prepared_and_is_reused_with_a_new_token(): void
    {
        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile, amountPen: '10.00');

        $prepared = PaymentOrder::create([
            'recharge_request_id' => $recharge->id,
            'attempt_number' => 1,
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'provider' => 'mercadopago',
            'provider_order_id' => null,
            'status' => 'pending',
            'submission_status' => 'prepared',
            'amount_minor' => 1000,
            'currency' => 'PEN',
        ]);

        Http::fake(['api.mercadopago.com/v1/payments' => Http::response(
            ['id' => 999, 'status' => 'approved', 'status_detail' => 'accredited'], 201
        )]);

        // Token NUEVO respecto al que hubiera usado el intento original —
        // válido porque nunca se envió nada.
        $order = (new MercadoPagoPaymentProvider())->createPaymentAttempt(
            $recharge,
            new YapePaymentInstrument(token: 'yape-token-brand-new')
        );

        $this->assertDatabaseCount('payment_orders', 1); // NUNCA una fila nueva
        $this->assertSame($prepared->id, $order->id);
        $this->assertSame(1, $order->attempt_number);
        $this->assertSame($prepared->idempotency_key, $order->idempotency_key);
        $this->assertSame('999', $order->provider_order_id);
        $this->assertSame('submitted', $order->submission_status);

        Http::assertSentCount(1); // un solo POST final
        Http::assertSent(fn ($request) => $request['payment_method_id'] === 'yape'
            && $request['token'] === 'yape-token-brand-new'
            && $request->hasHeader('X-Idempotency-Key', $prepared->idempotency_key));
    }

    /**
     * FAILURE BOUNDARY #2: submission_status se marca 'submitting' (la
     * escritura pre-I/O) y el proceso muere ANTES de que llegue ninguna
     * respuesta de Mercado Pago — indistinguible, para el resto del
     * sistema, de un timeout real. La SIGUIENTE request NUNCA hace un
     * segundo POST directo: entra por el mismo gate que 'uncertain' y
     * fuerza recuperación por external_reference primero.
     */
    public function test_submission_marked_submitting_when_process_dies_before_response_never_causes_a_second_post(): void
    {
        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile, amountPen: '10.00');

        PaymentOrder::create([
            'recharge_request_id' => $recharge->id,
            'attempt_number' => 1,
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'provider' => 'mercadopago',
            'provider_order_id' => null,
            'status' => 'pending',
            'submission_status' => 'submitting',
            'amount_minor' => 1000,
            'currency' => 'PEN',
        ]);

        Http::fake(['api.mercadopago.com/v1/payments/search*' => Http::response(
            ['paging' => ['total' => 0, 'limit' => 10, 'offset' => 0], 'results' => []],
            200
        )]);

        $this->expectException(RuntimeException::class);

        try {
            (new MercadoPagoPaymentProvider())->createPaymentAttempt($recharge, $this->cardInstrument());
        } finally {
            $this->assertDatabaseCount('payment_orders', 1);
            $postAttempts = collect(Http::recorded())
                ->filter(fn ($pair) => $pair[0]->url() === 'https://api.mercadopago.com/v1/payments');
            $this->assertCount(0, $postAttempts);
            Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/payments/search'));
        }
    }

    /**
     * Simula la decisión determinista que protege la concurrencia real
     * (dos requests simultáneos leyendo el mismo "último intento"): con el
     * lock de RechargeRequest + PaymentOrder dentro de la transacción de
     * resolveAttemptRow(), un segundo request que llega DESPUÉS de que el
     * primero ya confirmó un intento activo (provider_order_id ya
     * asignado) debe rechazar con una excepción controlada — nunca una
     * QueryException de UNIQUE ni un 500 crudo. No es una prueba de
     * concurrencia real con hilos/procesos (eso requiere ConcurrencyProbe,
     * deliberadamente NO ejecutado esta ronda — gate mova_qa) sino del
     * invariante determinista que la hace segura.
     */
    public function test_refuses_to_create_a_new_attempt_while_the_previous_one_is_still_active(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response(['id' => 1, 'status' => 'pending', 'status_detail' => 'x'], 201)]);

        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);
        $provider = new MercadoPagoPaymentProvider();
        $provider->createPaymentAttempt($recharge, $this->cardInstrument());

        Http::fake(); // un segundo intento aquí sería un bug

        $this->expectException(RuntimeException::class);
        try {
            $provider->createPaymentAttempt($recharge, $this->cardInstrument());
        } finally {
            Http::assertNothingSent();
            $this->assertDatabaseCount('payment_orders', 1); // nunca una QueryException por UNIQUE, nunca una fila extra
        }
    }

    public function test_refuses_to_create_a_new_attempt_when_the_previous_one_is_already_paid(): void
    {
        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);
        PaymentOrder::create([
            'recharge_request_id' => $recharge->id,
            'attempt_number' => 1,
            'provider' => 'mercadopago',
            'provider_order_id' => '999',
            'status' => 'paid',
            'amount_minor' => 1000,
            'currency' => 'PEN',
        ]);

        Http::fake();

        $this->expectException(RuntimeException::class);
        try {
            (new MercadoPagoPaymentProvider())->createPaymentAttempt($recharge, $this->cardInstrument());
        } finally {
            Http::assertNothingSent();
        }
    }

    // ---- errores de red/HTTP -------------------------------------------------

    /**
     * @dataProvider recognizedTerminal400Codes
     *
     * PROVIDER ERROR TAXONOMY: NO clasifica solo por status HTTP. Un 400
     * solo es terminal 'validation_error' cuando el body trae un código de
     * error OFICIALMENTE documentado como request/input (ver
     * MercadoPagoPaymentProvider::TERMINAL_400_CODES) — CIERTO que no se
     * creó nada Y que el payload de este intento es lo que está mal, así
     * que un intento nuevo con datos corregidos tiene sentido.
     */
    public function test_a_400_with_a_recognized_terminal_code_marks_the_attempt_failed(string $code): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response(['error' => $code, 'message' => 'x'], 400)]);

        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        $this->expectException(RuntimeException::class);

        try {
            (new MercadoPagoPaymentProvider())->createPaymentAttempt($recharge, $this->cardInstrument());
        } finally {
            $this->assertDatabaseCount('payment_orders', 1);
            $order = PaymentOrder::sole();
            $this->assertSame('failed', $order->status);
            $this->assertNull($order->submission_status);
            $this->assertNull($order->provider_order_id);
        }
    }

    public static function recognizedTerminal400Codes(): array
    {
        return [
            'json_syntax_error' => ['json_syntax_error'],
            'required_properties' => ['required_properties'],
            'property_value' => ['property_value'],
            'invalid_idempotency_key_length' => ['invalid_idempotency_key_length'],
        ];
    }

    /**
     * @dataProvider unrecognizedOrMissing400Codes
     *
     * "400 desconocido → fail closed": un 400 sin un código reconocido (o
     * sin ningún código extraíble del body) NUNCA se asume terminal — se
     * trata igual que cualquier respuesta ambigua: 'uncertain', mismo
     * intento, misma key, nunca un intento nuevo automático.
     */
    public function test_a_400_with_an_unrecognized_or_missing_code_fails_closed_as_uncertain(array $body): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response($body, 400)]);

        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        $this->expectException(RuntimeException::class);

        try {
            (new MercadoPagoPaymentProvider())->createPaymentAttempt($recharge, $this->cardInstrument());
        } finally {
            $this->assertDatabaseCount('payment_orders', 1);
            $order = PaymentOrder::sole();
            $this->assertSame('pending', $order->status);
            $this->assertSame('uncertain', $order->submission_status);
            $this->assertNull($order->provider_order_id);
        }
    }

    public static function unrecognizedOrMissing400Codes(): array
    {
        return [
            'sin ningún código' => [['message' => 'algo salió mal']],
            'código no reconocido' => [['error' => 'cc_rejected_insufficient_amount', 'message' => 'x']],
            'body vacío' => [[]],
        ];
    }

    /**
     * @dataProvider integrationAuthorizationErrorStatuses
     *
     * PROVIDER ERROR TAXONOMY: 401/403/404 son errores de INTEGRACIÓN/
     * AUTORIZACIÓN de MOVA — NUNCA un rechazo normal del pago (eso solo
     * ocurre vía status=rejected en un 2xx, ver
     * test_synchronous_rejected_response_is_reflected_locally_without_crediting()).
     * NUNCA se marca 'failed' (eso liberaría un reintento automático del
     * profesor — exactamente el loop que el encargo prohíbe): el intento
     * queda bloqueado operacionalmente (status sigue 'pending', lo que
     * bloquea resolveAttemptRow() igual que un intento activo) con
     * revisión DURABLE en la propia PaymentOrder.
     */
    public function test_401_403_404_block_operationally_and_never_allow_an_automatic_new_attempt(int $status): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response(['message' => 'auth error'], $status)]);

        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);
        $provider = new MercadoPagoPaymentProvider();

        try {
            $provider->createPaymentAttempt($recharge, $this->cardInstrument());
            $this->fail('Se esperaba RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('bloqueado para revisión operacional', $e->getMessage());
        }

        $this->assertDatabaseCount('payment_orders', 1);
        $order = PaymentOrder::sole();
        $this->assertSame('pending', $order->status); // NUNCA 'failed'
        $this->assertNull($order->submission_status);
        $this->assertNull($order->provider_order_id);
        $this->assertNotNull($order->review_reason);
        $this->assertStringContainsString('integración/autorización', $order->review_reason);
        $this->assertNotNull($order->review_detected_at);

        // El "no permitir loop de nuevos attempts del profesor" en la
        // práctica: un reintento inmediato NO crea un attempt 2 — queda
        // bloqueado por la misma fila hasta que un operador lo resuelva.
        Http::fake(); // cualquier llamada aquí sería un bug
        $this->expectException(RuntimeException::class);
        try {
            $provider->createPaymentAttempt($recharge, new YapePaymentInstrument(token: 'yape-retry'));
        } finally {
            $this->assertDatabaseCount('payment_orders', 1);
            Http::assertNothingSent();
        }
    }

    public static function integrationAuthorizationErrorStatuses(): array
    {
        return [
            'unauthorized' => [401],
            'forbidden' => [403],
            'not found' => [404],
        ];
    }

    /**
     * @dataProvider mercadoPagoTransientErrorStatuses
     *
     * P0 corregido: `>=400 => failed` NO es una regla segura. 409
     * (conflicto de idempotencia — documentado explícitamente por Mercado
     * Pago como evidencia de que la key YA se usó), 429 (rate limit,
     * transitorio, `usage_quota_exceeded`) y 5xx (server_error) NUNCA
     * asumen que el pago no se creó: el intento queda 'pending' con
     * submission_status='uncertain' — mismo intento, misma key, ningún
     * cobro nuevo automático.
     */
    public function test_leaves_the_attempt_uncertain_on_transient_mercadopago_error_statuses(int $status): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response(['message' => 'error'], $status)]);

        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        $this->expectException(RuntimeException::class);

        try {
            (new MercadoPagoPaymentProvider())->createPaymentAttempt($recharge, $this->cardInstrument());
        } finally {
            $this->assertDatabaseCount('payment_orders', 1);
            $order = PaymentOrder::sole();
            $this->assertSame('pending', $order->status);
            $this->assertSame('uncertain', $order->submission_status);
            $this->assertNull($order->provider_order_id);
        }
    }

    public static function mercadoPagoTransientErrorStatuses(): array
    {
        return [
            'idempotency conflict' => [409],
            'rate limited' => [429],
            'mercado pago caído' => [500],
            'gateway' => [503],
        ];
    }

    /**
     * Una respuesta 2xx pero inutilizable (sin id) NO da certeza de que
     * Mercado Pago haya o no creado el recurso — a diferencia de un 4xx/5xx
     * claro, aquí la fila se deja 'pending'/uncertain (fail closed hacia
     * "podría existir"), nunca se marca 'failed' sin esa certeza.
     */
    public function test_leaves_the_attempt_uncertain_on_response_missing_id(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response(['status' => 'approved'], 201)]);

        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        $this->expectException(RuntimeException::class);

        try {
            (new MercadoPagoPaymentProvider())->createPaymentAttempt($recharge, $this->cardInstrument());
        } finally {
            $this->assertDatabaseCount('payment_orders', 1);
            $order = PaymentOrder::sole();
            $this->assertSame('pending', $order->status);
            $this->assertSame('uncertain', $order->submission_status);
            $this->assertNull($order->provider_order_id);
        }
    }

    /**
     * P0 corregido (ronda de hardening distribuido): antes, una
     * ConnectionException se propagaba SIN capturar — el estado quedaba
     * 'pending'/reutilizable solo porque nada la tocaba (accidental, no
     * explícito). Ahora se captura, se envuelve en RuntimeException, y
     * submission_status se marca 'uncertain' EXPLÍCITAMENTE (ver migración
     * 2026_09_01_000006) — mismo resultado observable en la fila, pero ya
     * no depende de que nadie escriba nada.
     */
    public function test_leaves_the_attempt_uncertain_on_connection_exception(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        }]);

        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        $this->expectException(RuntimeException::class);

        try {
            (new MercadoPagoPaymentProvider())->createPaymentAttempt($recharge, $this->cardInstrument());
        } finally {
            $this->assertDatabaseCount('payment_orders', 1);
            $order = PaymentOrder::sole();
            $this->assertSame('pending', $order->status);
            $this->assertSame('uncertain', $order->submission_status);
            $this->assertNull($order->provider_order_id);
        }
    }

    public function test_throws_without_creating_an_order_when_access_token_missing(): void
    {
        config(['payments.mercadopago.access_token' => null]);
        Http::fake();

        [, $profile] = $this->teacher();
        $recharge = $this->recharge($profile);

        $this->expectException(RuntimeException::class);

        try {
            (new MercadoPagoPaymentProvider())->createPaymentAttempt($recharge, $this->cardInstrument());
        } finally {
            Http::assertNothingSent();
            $this->assertDatabaseCount('payment_orders', 0);
        }
    }

    // ---- fetchPayment() -------------------------------------------------------

    public function test_fetch_payment_returns_the_documented_fields(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/74581527758' => Http::response([
            'id' => 74581527758,
            'status' => 'approved',
            'status_detail' => 'accredited',
            'currency_id' => 'PEN',
            'transaction_amount' => 10.0,
            'transaction_amount_refunded' => 0,
            'external_reference' => 'recharge:1:attempt:1',
            'collector_id' => 470183340,
            'payment_method_id' => 'visa',
            'payment_type_id' => 'credit_card',
        ], 200)]);

        $truth = (new MercadoPagoPaymentProvider())->fetchPayment('74581527758');

        $this->assertSame('74581527758', $truth['id']);
        $this->assertSame('approved', $truth['status']);
        $this->assertSame('accredited', $truth['status_detail']);
        $this->assertSame('PEN', $truth['currency_id']);
        // assertEquals (no assertSame): PHP/JSON pueden ir y volver entre
        // int(10) y float(10.0) para un número sin parte fraccionaria —
        // fetchPayment() nunca decide nada por el TIPO de este valor
        // (siempre pasa por is_numeric()/(float) antes de comparar).
        $this->assertEquals(10.0, $truth['transaction_amount']);
        $this->assertSame('recharge:1:attempt:1', $truth['external_reference']);
        $this->assertSame('470183340', $truth['collector_id']);
        $this->assertSame('visa', $truth['payment_method_id']);
        // status_detail='accredited' (no 'pending_challenge') — el boundary
        // único de extracción (extractChallengeFields(), ver
        // mapPaymentJson()) nunca expone un Challenge para este estado.
        $this->assertNull($truth['three_ds_challenge_url']);
        $this->assertNull($truth['three_ds_creq']);
    }

    public function test_fetch_payment_returns_null_on_error_status(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/1' => Http::response(['message' => 'not found'], 404)]);

        $this->assertNull((new MercadoPagoPaymentProvider())->fetchPayment('1'));
    }

    public function test_fetch_payment_returns_null_without_access_token(): void
    {
        config(['payments.mercadopago.access_token' => null]);
        Http::fake();

        $this->assertNull((new MercadoPagoPaymentProvider())->fetchPayment('1'));
        Http::assertNothingSent();
    }

    public function test_fetch_payment_returns_null_on_connection_exception(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/1' => function () {
            throw new ConnectionException('timeout');
        }]);

        $this->assertNull((new MercadoPagoPaymentProvider())->fetchPayment('1'));
    }

    // ---- cancelPayment() (ronda de auditoría de seguridad financiera final)
    // -------------------------------------------------------------------------
    //
    // Clasificación de la respuesta HTTP de `PUT /v1/payments/{id}` — nunca
    // verdad financiera por sí sola (el llamador siempre relee vía
    // fetchPayment() después, ver
    // MercadoPagoPaymentReconciliationService::compensateLostChallenge()).
    // Los códigos numéricos 2018/2016/4017/2000 están documentados por la
    // referencia oficial de cancelación de Payments API (ver docblock de
    // cancelPayment()). Un 400 SIN ninguno de esos códigos ni de la tabla
    // API-wide confirmada ya NO se infiere como conflicto de estado "por
    // eliminación": cae en 'client_error_unclassified', honesto sobre la
    // falta de evidencia en esa respuesta puntual.

    public function test_cancel_payment_2xx_is_requested(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/501' => Http::response(
            ['id' => 501, 'status' => 'cancelled', 'status_detail' => 'by_collector'],
            200
        )]);

        $result = (new MercadoPagoPaymentProvider())->cancelPayment('501');

        $this->assertSame('requested', $result['outcome']);
    }

    public function test_cancel_payment_400_with_a_confirmed_input_error_code_is_invalid_request_never_not_cancellable(): void
    {
        // property_value está en la tabla confirmada de códigos de
        // request/input (TERMINAL_400_CODES) — un 400 con ESTE código es
        // un bug de integración (payload mal formado), nunca una carrera
        // de estado del pago.
        Http::fake(['api.mercadopago.com/v1/payments/502' => Http::response(
            ['error' => 'property_value', 'message' => 'invalid status value'],
            400
        )]);

        $result = (new MercadoPagoPaymentProvider())->cancelPayment('502');

        $this->assertSame('invalid_request', $result['outcome']);
    }

    public function test_cancel_payment_400_with_numeric_code_2018_is_not_cancellable(): void
    {
        // 2018 = "action invalid for current payment state" — documentado
        // por la referencia oficial de cancelación de Payments API.
        Http::fake(['api.mercadopago.com/v1/payments/512' => Http::response(
            ['message' => 'invalid state', 'cause' => [['code' => 2018, 'description' => 'the action requested is not valid for the current payment state']]],
            400
        )]);

        $result = (new MercadoPagoPaymentProvider())->cancelPayment('512');

        $this->assertSame('not_cancellable', $result['outcome']);
    }

    /**
     * @dataProvider documentedInvalidRequestCodes
     */
    public function test_cancel_payment_400_with_numeric_codes_2016_or_4017_is_invalid_request(int $code): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/513' => Http::response(
            ['message' => 'invalid request', 'cause' => [['code' => $code, 'description' => 'invalid state update/request']]],
            400
        )]);

        $result = (new MercadoPagoPaymentProvider())->cancelPayment('513');

        $this->assertSame('invalid_request', $result['outcome']);
    }

    /**
     * 2016 = "invalid state/update request", 4017 = "status cannot be
     * null" — ambos documentados por la referencia oficial de cancelación
     * de Payments API.
     */
    public static function documentedInvalidRequestCodes(): array
    {
        return [[2016], [4017]];
    }

    public function test_cancel_payment_400_without_any_recognized_code_is_client_error_unclassified_never_a_state_conflict_guess(): void
    {
        // Sin NINGÚN código reconocido en esta respuesta puntual (ni los
        // numéricos documentados de cancelación — 2018/2016/4017 — ni la
        // tabla API-wide confirmada): sin evidencia en ESTE body concreto,
        // MOVA ya no adivina la causa — nunca 'not_cancellable' por
        // defecto.
        Http::fake(['api.mercadopago.com/v1/payments/503' => Http::response(
            ['message' => 'cannot update payment with current status'],
            400
        )]);

        $result = (new MercadoPagoPaymentProvider())->cancelPayment('503');

        $this->assertSame('client_error_unclassified', $result['outcome']);
        $this->assertNotSame('not_cancellable', $result['outcome']);
    }

    public function test_cancel_payment_400_without_any_body_is_client_error_unclassified(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/504' => Http::response([], 400)]);

        $result = (new MercadoPagoPaymentProvider())->cancelPayment('504');

        $this->assertSame('client_error_unclassified', $result['outcome']);
    }

    /**
     * @dataProvider authConfigStatuses
     */
    public function test_cancel_payment_401_403_are_auth_config_error(int $status): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/505' => Http::response(['message' => 'unauthorized'], $status)]);

        $result = (new MercadoPagoPaymentProvider())->cancelPayment('505');

        $this->assertSame('auth_config_error', $result['outcome']);
    }

    public static function authConfigStatuses(): array
    {
        return [[401], [403]];
    }

    public function test_cancel_payment_404_is_payment_not_found_distinct_from_auth_config_error(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/506' => Http::response(['message' => 'not found'], 404)]);

        $result = (new MercadoPagoPaymentProvider())->cancelPayment('506');

        $this->assertSame('payment_not_found', $result['outcome']);
        $this->assertNotSame('auth_config_error', $result['outcome']);
        $this->assertNotSame('not_cancellable', $result['outcome']);
    }

    public function test_cancel_payment_429_is_rate_limited(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/507' => Http::response(['message' => 'usage_quota_exceeded'], 429)]);

        $result = (new MercadoPagoPaymentProvider())->cancelPayment('507');

        $this->assertSame('rate_limited', $result['outcome']);
    }

    /**
     * @dataProvider serverErrorStatuses
     */
    public function test_cancel_payment_5xx_is_server_error(int $status): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/508' => Http::response(['message' => 'internal_error'], $status)]);

        $result = (new MercadoPagoPaymentProvider())->cancelPayment('508');

        $this->assertSame('server_error', $result['outcome']);
    }

    public static function serverErrorStatuses(): array
    {
        return [[500], [503]];
    }

    public function test_cancel_payment_connection_exception_is_transport_uncertain(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/509' => function () {
            throw new ConnectionException('timeout');
        }]);

        $result = (new MercadoPagoPaymentProvider())->cancelPayment('509');

        $this->assertSame('transport_uncertain', $result['outcome']);
    }

    public function test_cancel_payment_without_access_token_is_transport_uncertain_and_sends_nothing(): void
    {
        config(['payments.mercadopago.access_token' => null]);
        Http::fake();

        $result = (new MercadoPagoPaymentProvider())->cancelPayment('510');

        $this->assertSame('transport_uncertain', $result['outcome']);
        Http::assertNothingSent();
    }

    public function test_cancel_payment_uses_put_with_the_documented_body_never_orders_api(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/511' => Http::response(['id' => 511, 'status' => 'cancelled'], 200)]);

        (new MercadoPagoPaymentProvider())->cancelPayment('511');

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && $request->url() === 'https://api.mercadopago.com/v1/payments/511'
                && $request['status'] === 'cancelled'
                && ! str_contains($request->url(), '/orders/');
        });
    }

    // ---- verifyWebhook() ------------------------------------------------------

    public function test_verify_webhook_returns_event_for_valid_signature_and_well_formed_payload(): void
    {
        $payload = ['id' => '123456', 'action' => 'payment.updated', 'type' => 'payment', 'data' => ['id' => '74581527758']];
        $raw = json_encode($payload);

        $event = (new MercadoPagoPaymentProvider())->verifyWebhook($raw, $this->validHeadersFor('74581527758'));

        $this->assertNotNull($event);
        $this->assertSame('123456', $event->eventId);
        $this->assertSame('payment.updated', $event->eventType);
        $this->assertSame('74581527758', $event->providerOrderId);
        $this->assertSame('pending', $event->status);
    }

    public function test_verify_webhook_returns_null_for_invalid_signature(): void
    {
        $payload = ['id' => '123456', 'data' => ['id' => '74581527758']];
        $raw = json_encode($payload);

        $headers = $this->validHeadersFor('74581527758');
        $headers['x-signature'] = 'ts=1700000000000,v1='.str_repeat('0', 64);

        $this->assertNull((new MercadoPagoPaymentProvider())->verifyWebhook($raw, $headers));
    }

    public function test_verify_webhook_throws_for_non_json_body_despite_valid_signature(): void
    {
        $this->expectException(MalformedWebhookPayloadException::class);

        (new MercadoPagoPaymentProvider())->verifyWebhook('not json', $this->validHeadersFor('74581527758'));
    }

    public function test_verify_webhook_throws_when_root_id_missing(): void
    {
        $payload = ['data' => ['id' => '74581527758']];

        $this->expectException(MalformedWebhookPayloadException::class);

        (new MercadoPagoPaymentProvider())->verifyWebhook(json_encode($payload), $this->validHeadersFor('74581527758'));
    }

    public function test_verify_webhook_throws_when_data_id_missing(): void
    {
        $payload = ['id' => '123456'];

        $this->expectException(MalformedWebhookPayloadException::class);

        (new MercadoPagoPaymentProvider())->verifyWebhook(json_encode($payload), $this->validHeadersFor(null));
    }

    public function test_verify_webhook_throws_when_type_is_not_payment(): void
    {
        // topic_chargebacks_wh (u otro tópico) NUNCA debe procesarse como
        // notificación de pago por este endpoint.
        $payload = ['id' => '123456', 'type' => 'topic_chargebacks_wh', 'data' => ['id' => '74581527758']];

        $this->expectException(MalformedWebhookPayloadException::class);

        (new MercadoPagoPaymentProvider())->verifyWebhook(json_encode($payload), $this->validHeadersFor('74581527758'));
    }

    public function test_verify_webhook_throws_when_live_mode_does_not_match_expected_environment(): void
    {
        config(['payments.mercadopago.expected_live_mode' => false]);
        $payload = ['id' => '123456', 'type' => 'payment', 'live_mode' => true, 'data' => ['id' => '74581527758']];

        $this->expectException(MalformedWebhookPayloadException::class);

        (new MercadoPagoPaymentProvider())->verifyWebhook(json_encode($payload), $this->validHeadersFor('74581527758'));
    }

    public function test_verify_webhook_accepts_live_mode_matching_expected_environment(): void
    {
        config(['payments.mercadopago.expected_live_mode' => false]);
        $payload = ['id' => '123456', 'type' => 'payment', 'live_mode' => false, 'data' => ['id' => '74581527758']];

        $event = (new MercadoPagoPaymentProvider())->verifyWebhook(json_encode($payload), $this->validHeadersFor('74581527758'));

        $this->assertNotNull($event);
    }

    public function test_verify_webhook_throws_when_application_id_does_not_match_configured_application(): void
    {
        config(['payments.mercadopago.application_id' => '8607626959814761']);
        $payload = ['id' => '123456', 'type' => 'payment', 'application_id' => '999999999999999', 'data' => ['id' => '74581527758']];

        $this->expectException(MalformedWebhookPayloadException::class);

        (new MercadoPagoPaymentProvider())->verifyWebhook(json_encode($payload), $this->validHeadersFor('74581527758'));
    }

    /**
     * P0 corregido: la documentación oficial actual del tópico `payment` NO
     * garantiza `application_id` en el body (solo action/api_version/
     * data.id/date_created/id/live_mode/type/user_id) — un webhook válido
     * y bien formado sin ese campo NUNCA debe rechazarse como malformed,
     * incluso con application_id esperado configurado.
     */
    public function test_verify_webhook_accepts_a_valid_payment_notification_without_application_id_field(): void
    {
        config(['payments.mercadopago.application_id' => '8607626959814761']);
        $payload = ['action' => 'payment.updated', 'api_version' => 'v1', 'id' => '123456', 'live_mode' => false, 'type' => 'payment', 'user_id' => 724484980, 'data' => ['id' => '74581527758']];

        $event = (new MercadoPagoPaymentProvider())->verifyWebhook(json_encode($payload), $this->validHeadersFor('74581527758'));

        $this->assertNotNull($event);
        $this->assertSame('74581527758', $event->providerOrderId);
    }

    public function test_verify_webhook_skips_application_id_check_when_not_configured(): void
    {
        config(['payments.mercadopago.application_id' => null]);
        $payload = ['id' => '123456', 'type' => 'payment', 'application_id' => 'lo-que-sea', 'data' => ['id' => '74581527758']];

        $event = (new MercadoPagoPaymentProvider())->verifyWebhook(json_encode($payload), $this->validHeadersFor('74581527758'));

        $this->assertNotNull($event);
    }

    public function test_verify_webhook_throws_when_user_id_does_not_match_expected_collector(): void
    {
        config(['payments.mercadopago.expected_collector_id' => '470183340']);
        $payload = ['id' => '123456', 'type' => 'payment', 'user_id' => '111111111', 'data' => ['id' => '74581527758']];

        $this->expectException(MalformedWebhookPayloadException::class);

        (new MercadoPagoPaymentProvider())->verifyWebhook(json_encode($payload), $this->validHeadersFor('74581527758'));
    }

    // ---- helpers ------------------------------------------------------------

    private function cardInstrument(): CardPaymentInstrument
    {
        return new CardPaymentInstrument(
            token: 'card-token-abc',
            paymentMethodId: 'visa',
            installments: 1,
        );
    }

    /**
     * Firma REALMENTE válida para $dataId — el algoritmo en sí ya está
     * cubierto por MercadoPagoWebhookSignatureVerifierTest (sin cambios en
     * este pivot — genérico, no específico de un tópico).
     */
    private function validHeadersFor(?string $dataId): array
    {
        $ts = '1700000000000';
        $requestId = 'req-123';
        $secret = 'unit-test-webhook-secret';

        $manifest = '';
        if ($dataId !== null) {
            $manifest .= 'id:'.strtolower($dataId).';';
        }
        $manifest .= 'request-id:'.$requestId.';';
        $manifest .= 'ts:'.$ts.';';

        return [
            'x-signature' => 'ts='.$ts.',v1='.hash_hmac('sha256', $manifest, $secret),
            'x-request-id' => $requestId,
            'data_id_query' => $dataId,
        ];
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

    private function recharge(TeacherProfile $profile, string $amountPen = '10.00', int $credits = 5): RechargeRequest
    {
        $operation = fake()->unique()->numerify('OP########');

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
