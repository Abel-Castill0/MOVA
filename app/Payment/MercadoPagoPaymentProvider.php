<?php

namespace App\Payment;

use App\Models\PaymentOrder;
use App\Models\RechargeRequest;
use App\Payment\Contracts\CardPaymentInstrument;
use App\Payment\Contracts\PaymentProviderContract;
use App\Payment\Contracts\TokenizedPaymentInstrument;
use App\Payment\Contracts\YapePaymentInstrument;
use App\Payment\MercadoPago\MalformedWebhookPayloadException;
use App\Payment\MercadoPago\MercadoPagoPaymentStatusMapper;
use App\Payment\MercadoPago\MercadoPagoWebhookSignatureVerifier;
use App\Services\MercadoPagoPaymentReconciliationService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Integración real con Mercado Pago **Payments API** (`POST /v1/payments`,
 * `GET /v1/payments/{id}`) — REEMPLAZA la integración Orders API de la
 * ronda anterior (decisión arquitectónica explícita: Yape no está
 * documentado oficialmente sobre Orders API, solo sobre Payments API —
 * verificado vía MCP oficial, no inferido; ver el registro de la sesión de
 * pivot). Una sola familia de recurso remoto para cards y Yape: un pago de
 * Mercado Pago, `GET /v1/payments/{id}` como única fuente de verdad, un
 * solo mapper de estado (MercadoPagoPaymentStatusMapper), un solo tópico de
 * webhook principal (`payment`).
 *
 * Sin SDK oficial — igual criterio de Dependency Budget que la integración
 * anterior: HTTP directo vía `Http` facade.
 *
 * NUNCA confía en la respuesta síncrona de creación para ACREDITAR, aunque
 * Payments API normalmente resuelve el pago de forma síncrona (a diferencia
 * de Orders API en modo manual): createPaymentAttempt() solo refleja
 * localmente resultados NEGATIVOS sin riesgo (rejected/cancelled →
 * 'failed'); cualquier otro resultado (incluido 'approved/accredited')
 * queda 'pending' localmente — la única vía que acredita de verdad es la
 * reconciliación server-to-server (webhook + fetchPayment(), ver
 * MercadoPagoPaymentReconciliationService). Esto evita un segundo camino
 * de crédito y mantiene RechargeApprovalService::credit() como el único
 * choke point, sin importar cuán "definitiva" parezca la respuesta
 * inmediata de creación.
 *
 * `payer.email` es SIEMPRE el del profesor autenticado dueño de la
 * RechargeRequest (`$recharge->teacherProfile->user->email`) — nunca un
 * valor que el frontend pueda inyectar vía TokenizedPaymentInstrument (esos
 * DTOs no tienen ningún campo de email a propósito).
 */
class MercadoPagoPaymentProvider implements PaymentProviderContract
{
    public function __construct(
        private readonly MercadoPagoWebhookSignatureVerifier $signatureVerifier = new MercadoPagoWebhookSignatureVerifier()
    ) {
    }

    /**
     * @param  TokenizedPaymentInstrument|null  $instrument  Token ya generado por
     *         Card Payment Brick (CardPaymentInstrument) o `mp.yape.create()`
     *         (YapePaymentInstrument) — sin esto no hay forma de crear un
     *         pago real, se lanza una excepción clara en vez de fabricar un
     *         intento vacío.
     */
    public function createPaymentAttempt(RechargeRequest $recharge, ?TokenizedPaymentInstrument $instrument = null): PaymentOrder
    {
        $accessToken = config('payments.mercadopago.access_token');
        if (! $accessToken) {
            throw new RuntimeException(
                'MercadoPagoPaymentProvider: MERCADOPAGO_ACCESS_TOKEN no está configurado. '
                .'No se puede crear un intento de pago sin credenciales.'
            );
        }

        if ($instrument === null) {
            throw new RuntimeException(
                'MercadoPagoPaymentProvider: falta un medio de pago tokenizado (Card Payment Brick '
                .'o mp.yape). POST /v1/payments no puede armarse sin transaction_amount+token — no '
                .'existe un paso previo de "crear intento vacío" en Payments API.'
            );
        }

        // Decisión atómica de QUÉ fila usar (crear una nueva vs. reutilizar
        // un intento anterior nunca confirmado) bajo lock — ver
        // resolveAttemptRow(). El POST a Mercado Pago SIEMPRE corre después
        // del commit, nunca con el lock sostenido (network I/O dentro de una
        // transacción de base de datos es exactamente lo que esta ronda
        // corrige).
        $order = $this->resolveAttemptRow($recharge);

        if (in_array($order->submission_status, ['submitting', 'uncertain'], true)) {
            // TOKEN + IDEMPOTENCY INVARIANT: esta fila ya tiene una
            // X-Idempotency-Key persistida y un intento POST previo cuyo
            // resultado nunca se confirmó — ya sea porque el proceso murió
            // DESPUÉS de comprometer 'submitting' pero antes/durante el
            // POST (ver el marcador pre-I/O más abajo), o porque el POST sí
            // corrió y devolvió timeout/5xx/429/2xx sin id/conflicto de
            // idempotencia (ver classifyHttpFailure()). Ambos casos se
            // tratan IGUAL aquí: NUNCA se reenvía un payload nuevo (que
            // podría traer un token de tarjeta/Yape DISTINTO al de la
            // primera vez) bajo la MISMA key sin antes intentar resolver la
            // incertidumbre.
            //
            // Se ejecuta la recuperación dirigida por external_reference
            // (fuera de cualquier lock — es una llamada de red) y, pase lo
            // que pase, este método NUNCA continúa hacia un POST en esta
            // misma invocación: si se resolvió, la fila ya no calza en
            // ninguna rama de resolveAttemptRow() que permita reutilizarla,
            // así que el llamador debe invocar createPaymentAttempt() de
            // nuevo para que decida correctamente sobre el estado ya
            // resuelto (rechazar si quedó pagada/activa, o crear un intento
            // nuevo con una key nueva si quedó terminal-failed). Si sigue
            // incierta, fail closed — nunca un segundo cobro automático.
            $outcome = app(MercadoPagoPaymentReconciliationService::class)
                ->reconcileUncertainSubmission($order, $this);

            throw new RuntimeException(
                "MercadoPagoPaymentProvider: RechargeRequest#{$recharge->id} tiene un intento de pago previo "
                ."con resultado incierto todavía sin resolver (verificación: {$outcome}) — no se envía un cobro "
                .'nuevo automáticamente. Vuelve a intentarlo en unos segundos; MOVA seguirá intentando resolverlo.'
            );
        }

        $email = $recharge->teacherProfile->user->email ?? null;

        // instrumentPayload() nunca decide 'payer' — solo token/payment_method_id/
        // installments/issuer_id y, para tarjetas, una 'identification' suelta
        // que se anida aquí bajo 'payer' junto al email server-trusted. Evita
        // que un array spread pise silenciosamente el payer.email ya armado.
        $instrumentData = $this->instrumentPayload($instrument);
        $identification = $instrumentData['identification'] ?? null;
        unset($instrumentData['identification']);

        $payer = array_filter(['email' => $email], static fn ($v) => $v !== null);
        if ($identification !== null) {
            $payer['identification'] = $identification;
        }

        $payload = [
            // Número real, NUNCA string — a diferencia de Orders API,
            // Payments API documenta transaction_amount como número JSON
            // (ver ejemplos oficiales: `"transaction_amount": 5000`). Esto
            // es solo el formato de TRANSPORTE — la comparación de verdad
            // (fetchPayment() vs PaymentOrder.amount_minor) sigue siendo
            // siempre en centavos enteros, nunca float (sección 8/9).
            'transaction_amount' => $this->amountMinorToApiNumber($order->amount_minor),
            'description' => 'Recarga de créditos MOVA: '.$recharge->package_name,
            'external_reference' => $order->externalReference(),
            'payer' => $payer,
            ...$instrumentData,
        ];

        // CRITICAL ORDERING (sección PRE-SUBMISSION CRASH BOUNDARY): esta
        // escritura persiste ANTES de cualquier I/O de red, y es una
        // sentencia UPDATE suelta (autocommit) — NUNCA dentro de una
        // transacción abierta que siga viva durante el POST. Es lo que
        // hace recuperable un crash del proceso justo aquí: si el proceso
        // muere entre esta línea y recibir respuesta de Mercado Pago, la
        // PRÓXIMA llamada a resolveAttemptRow() encuentra
        // submission_status='submitting' (no 'prepared') y lo trata como
        // incierto — nunca como "nada se envió todavía".
        $order->update(['submission_status' => 'submitting']);

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->withHeaders([
                    // PERSISTIDA en payment_orders.idempotency_key (ver
                    // resolveAttemptRow()) — nunca derivada de nuevo en cada
                    // llamada. Un reintento HTTP del MISMO intento (timeout,
                    // conexión perdida) reutiliza literalmente el mismo valor
                    // guardado; Mercado Pago documenta que una key repetida
                    // devuelve la respuesta del intento original en vez de
                    // crear un pago duplicado — es el mecanismo oficial en el
                    // que nos apoyamos para el caso "la respuesta se perdió
                    // pero el pago sí se creó", sin inventar un provider
                    // payment id propio.
                    'X-Idempotency-Key' => $order->idempotency_key,
                ])
                ->post($this->baseUrl().'/v1/payments', $payload);
        } catch (Throwable $e) {
            // HTTP ERROR SEMANTICS / NETWORK TIMEOUT SEMANTICS: no llegó
            // NINGUNA respuesta — no hay forma de saber si Mercado Pago
            // llegó a crear el pago antes de que la conexión se cortara.
            // NUNCA se asume "no se creó" aquí: se marca 'uncertain'
            // (nunca 'failed'), se conserva el mismo local attempt y la
            // misma X-Idempotency-Key — la recuperación por
            // external_reference (ver el gate de arriba en la próxima
            // invocación) es la única vía autorizada para resolverlo.
            Log::error('[MercadoPago] Excepción de red al crear el pago — resultado incierto, nunca se asume que no se creó.', [
                'recharge_request_id' => $recharge->id,
                'payment_order_id' => $order->id,
                'attempt_number' => $order->attempt_number,
                'error' => $e->getMessage(),
            ]);

            $order->update(['submission_status' => 'uncertain']);

            throw new RuntimeException(
                "MercadoPagoPaymentProvider: excepción de red al crear el pago para RechargeRequest#{$recharge->id} "
                .'— resultado incierto (no se descarta ni se confirma). El mismo intento y la misma '
                .'X-Idempotency-Key se reutilizarán; MOVA intentará resolverlo vía recuperación antes de '
                .'permitir un cobro nuevo.',
                previous: $e
            );
        }

        if (! $response->successful()) {
            $category = $this->classifyHttpFailure($response);
            $providerStatus = 'http_'.$response->status();

            Log::log($category === 'auth_config_error' ? 'critical' : 'error',
                '[MercadoPago] Error al crear el pago.', [
                    // Nunca access_token/headers/payload completo — solo
                    // identificadores locales y el status/categoría/mensaje
                    // de error acotado (safeErrorFromResponse() ya trunca y
                    // nunca vuelca el body crudo completo).
                    'recharge_request_id' => $recharge->id,
                    'payment_order_id' => $order->id,
                    'attempt_number' => $order->attempt_number,
                    'status' => $response->status(),
                    'categoria' => $category,
                    'error' => $this->safeErrorFromResponse($response),
                ]);

            if ($category === 'validation_error') {
                // ÚNICA categoría donde un código de error CONCRETO,
                // oficialmente documentado, confirma que el problema es del
                // payload de ESTE intento (dato malformado/faltante) — ver
                // classifyHttpFailure()/isConfirmedTerminalValidationError().
                // CIERTO que no se creó ningún recurso remoto, y un intento
                // NUEVO con datos corregidos tiene sentido: libera una key
                // NUEVA la próxima vez que se llame a createPaymentAttempt().
                $order->update([
                    'status' => 'failed',
                    'submission_status' => null,
                    'provider_status' => $providerStatus,
                ]);

                throw new RuntimeException(
                    "MercadoPagoPaymentProvider: Mercado Pago rechazó la creación del pago por un error de "
                    ."request/input DEFINITIVO y documentado (HTTP {$response->status()})."
                );
            }

            if ($category === 'auth_config_error') {
                // 401/403/404 en /v1/payments: error de INTEGRACIÓN/
                // AUTORIZACIÓN de MOVA (token inválido, cuenta bloqueada,
                // endpoint mal configurado) — NUNCA un rechazo normal del
                // pago (eso es status=rejected en un 2xx, ver
                // MercadoPagoPaymentStatusMapper). CIERTO que no se creó
                // nada, pero la causa NO es de este intento — un profesor
                // reintentando solo repetiría el mismo error para siempre.
                // Bloqueo operacional: se deja `status` SIN tocar (sigue
                // 'pending', lo que bloquea resolveAttemptRow() igual que
                // un intento activo — nunca 'failed', que liberaría un
                // reintento automático) y se registra revisión DURABLE en
                // la propia PaymentOrder — un operador debe resolverlo a
                // mano antes de que la recarga pueda volver a intentarse.
                $order->update(['submission_status' => null, 'provider_status' => $providerStatus]);
                app(MercadoPagoPaymentReconciliationService::class)->markReview(
                    $order,
                    null,
                    "Error de integración/autorización de Mercado Pago al crear el pago (HTTP {$response->status()}) "
                    .'— requiere revisión operacional; no se permite un nuevo intento automático del profesor.'
                );

                throw new RuntimeException(
                    "MercadoPagoPaymentProvider: error de integración/autorización de Mercado Pago "
                    ."(HTTP {$response->status()}) — bloqueado para revisión operacional, ningún intento "
                    .'nuevo automático.'
                );
            }

            // idempotency_conflict / rate_limited / server_error /
            // unclassified (incluye un 400 cuyo código no está en la lista
            // confirmada — ver classifyHttpFailure()): NUNCA se asume que
            // el pago no se creó (un 409 idempotency_key_already_used en
            // particular es evidencia de que SÍ existe algo bajo esta key).
            // Uncertain, mismo intento, misma key, nunca 'failed', nunca un
            // segundo cobro automático — el gate de arriba resolverá esto
            // en la próxima invocación.
            $order->update(['submission_status' => 'uncertain', 'provider_status' => $providerStatus]);

            throw new RuntimeException(
                "MercadoPagoPaymentProvider: respuesta incierta de Mercado Pago al crear el pago "
                ."(HTTP {$response->status()}, categoría: {$category}) — no se descarta ni se confirma; "
                .'MOVA lo resolverá vía recuperación antes de permitir un cobro nuevo.'
            );
        }

        $paymentId = $response->json('id');
        $status = $response->json('status');
        $statusDetail = $response->json('status_detail');

        if ($paymentId === null || (! is_int($paymentId) && ! is_string($paymentId))) {
            Log::error('[MercadoPago] Respuesta 2xx sin id de pago — resultado inutilizable.', [
                'recharge_request_id' => $recharge->id,
                'body' => $this->safeErrorFromResponse($response),
            ]);

            // A diferencia del caso anterior, aquí SÍ hubo un 2xx — no hay
            // certeza de que Mercado Pago no haya creado el recurso (el
            // problema es que su respuesta es inutilizable, no que haya
            // rechazado el pago). Uncertain/reutilizable — nunca se marca
            // 'failed' sin esa certeza (fail closed hacia "podría existir").
            $order->update(['submission_status' => 'uncertain', 'provider_status' => 'http_'.$response->status().'_sin_id']);

            throw new RuntimeException('MercadoPagoPaymentProvider: Mercado Pago respondió 2xx sin id de pago — resultado incierto.');
        }

        // Solo se refleja localmente un resultado NEGATIVO síncrono
        // (rejected/cancelled → 'failed', sin riesgo financiero). Cualquier
        // otro resultado, incluido 'approved/accredited', queda 'pending' —
        // ver docblock de la clase. Un 2xx CON id utilizable siempre resuelve
        // la incertidumbre de este intento, sin importar cómo terminó
        // normalizándose el estado.
        $normalized = is_string($status) ? MercadoPagoPaymentStatusMapper::normalize($status, $statusDetail) : 'review';
        $localStatus = $normalized === 'failed' ? 'failed' : 'pending';

        $order->update([
            'provider_order_id' => (string) $paymentId,
            'status' => $localStatus,
            // 'submitted': provider payment id conocido — sin importar el
            // ESTADO DE NEGOCIO del pago (approved/pending/rejected son los
            // tres igualmente 'submitted' desde la perspectiva de "¿llegó a
            // existir un recurso remoto con id?"; PaymentStatusMapper ya
            // decidió $localStatus arriba, un eje totalmente separado).
            'submission_status' => 'submitted',
            'provider_status' => is_string($status) ? $status : null,
            'provider_status_detail' => $statusDetail,
        ]);

        return $order->fresh();
    }

    /**
     * Determina ATÓMICAMENTE qué fila PaymentOrder usar para este intento
     * — la única sección con lock de fila de todo el método, y nunca
     * incluye la llamada HTTP (sección "ATTEMPT CONCURRENCY" del encargo).
     *
     * lockForUpdate() sobre la RechargeRequest serializa a cualquier
     * request concurrente para la MISMA recarga: el segundo, al obtener el
     * lock recién liberado por el primero, ve el estado YA actualizado y
     * decide correctamente en vez de competir por el mismo INSERT (nunca
     * una QueryException de UNIQUE por una carrera — la decisión ya fue
     * tomada bajo lock antes de intentar crear nada).
     *
     * SUBMISSION LIFECYCLE (ronda del pre-commit gate) —
     * `payment_orders.submission_status` tiene cuatro valores activos,
     * elegidos para modelar EXACTAMENTE la pregunta que resolveAttemptRow()
     * necesita responder ("¿puedo reutilizar/crear esta fila con
     * seguridad?"), sin construir una state machine grande:
     *
     *   - `prepared`  — fila creada y comprometida, NINGÚN POST se intentó
     *     todavía (valor inicial al crear una fila, y el estado en el que
     *     queda una fila si el proceso muere ANTES de la marca 'submitting'
     *     de abajo). Reutilizable DIRECTAMENTE, incluso con un token/medio
     *     de pago nuevo — nada se envió, cero riesgo de un segundo cobro.
     *   - `submitting` — a punto de/enviando el POST; se persiste (UPDATE
     *     suelto, autocommit) INMEDIATAMENTE ANTES de la llamada HTTP, para
     *     que un crash justo ahí deje un rastro recuperable. Si el proceso
     *     muere aquí, el resultado se trata como incierto — igual que
     *     'uncertain' (ver abajo), NUNCA como 'prepared'.
     *   - `uncertain` — el POST corrió y no dio una respuesta definitiva
     *     (transport exception, 5xx, 429, 2xx sin id, conflicto de
     *     idempotencia — ver classifyHttpFailure()).
     *   - `submitted` — provider payment id conocido (2xx con id, sin
     *     importar el estado de negocio del pago).
     *
     * `submitting` y `uncertain` se tratan IGUAL en resolveAttemptRow() y
     * en el gate de createPaymentAttempt(): la fila se devuelve, pero el
     * llamador NUNCA la reutiliza directamente para un POST — primero
     * fuerza la recuperación por external_reference (sección "TOKEN +
     * IDEMPOTENCY INVARIANT"). `null` (sin uno de los cuatro valores) cubre
     * dos casos que deben BLOQUEAR igual que un intento activo, nunca
     * reutilizarse como 'prepared': una fila confirmada activa/pagada
     * (`submitted` ya limpio a null tras una reconciliación, con `status`
     * gobernando el bloqueo) y un bloqueo OPERACIONAL (401/403/404 — error
     * de integración/autorización, `status` se deja 'pending' a propósito
     * para que este mismo bloqueo aplique, ver el manejo de
     * 'auth_config_error' arriba).
     *
     * Cuatro desenlaces:
     *   1. No existe ningún intento previo → crea el primero (attempt 1,
     *      `submission_status='prepared'`).
     *   2. El último intento quedó `submission_status='prepared'` (nunca se
     *      intentó ningún POST — incluye el caso "el proceso murió justo
     *      después de comprometer el intento, antes de iniciar el envío")
     *      → se REUTILIZA esa misma fila DIRECTAMENTE, con cualquier
     *      token/medio de pago que traiga esta llamada.
     *   3. El último intento quedó `submission_status` en `submitting` o
     *      `uncertain` → se DEVUELVE esa misma fila, pero el llamador
     *      fuerza recuperación antes de cualquier POST nuevo (ver arriba).
     *   4. El último intento sigue activo de cualquier otra forma (pending
     *      confirmado, pagado, o bloqueado operacionalmente) → rechaza con
     *      una excepción controlada (nunca QueryException/500) — el
     *      llamador decide qué responder. Esto también cubre la carrera
     *      legítima de dos requests concurrentes: el segundo, tras esperar
     *      el lock, ve la fila recién creada por el primero (todavía
     *      'prepared' o ya 'submitting') y — OJO — SÍ podría calzar en el
     *      desenlace 2/3 de arriba en vez de rechazar; eso es correcto y
     *      seguro: 'prepared' significa que aún no se envió nada, así que
     *      "reutilizar" es sencillamente "usar la fila que el primer
     *      request ya reservó bajo el mismo lock", nunca una segunda fila.
     *   5. El último intento terminó en un estado terminal negativo
     *      (failed/cancelled/expired) → crea un intento NUEVO con
     *      attempt_number+1, una idempotency_key NUEVA, y
     *      `submission_status='prepared'`.
     */
    private function resolveAttemptRow(RechargeRequest $recharge): PaymentOrder
    {
        return DB::transaction(function () use ($recharge) {
            // Fila canónica a lockear: la RechargeRequest, no un
            // PaymentOrder que quizás todavía no exista (el primer intento
            // de una recarga no tiene ninguna fila previa que lockear).
            RechargeRequest::whereKey($recharge->id)->lockForUpdate()->firstOrFail();

            $latest = PaymentOrder::where('recharge_request_id', $recharge->id)
                ->orderByDesc('attempt_number')
                ->lockForUpdate()
                ->first();

            if ($latest !== null && $latest->submission_status === 'prepared') {
                // Nunca se intentó ningún POST — reutilizable directamente,
                // incluso con un token/medio de pago nuevo.
                return $latest;
            }

            if ($latest !== null && in_array($latest->submission_status, ['submitting', 'uncertain'], true)) {
                return $latest;
            }

            if ($latest !== null && ! in_array($latest->status, ['failed', 'cancelled', 'expired'], true)) {
                throw new RuntimeException(
                    "MercadoPagoPaymentProvider: RechargeRequest#{$recharge->id} ya tiene un intento de pago "
                    ."en estado '{$latest->status}' — no se crea uno nuevo mientras ese siga activo o pagado."
                );
            }

            return PaymentOrder::create([
                'recharge_request_id' => $recharge->id,
                'attempt_number' => $latest ? $latest->attempt_number + 1 : 1,
                'idempotency_key' => (string) Str::uuid(),
                'provider' => 'mercadopago',
                'provider_order_id' => null,
                'submission_status' => 'prepared',
                'status' => 'pending',
                'amount_minor' => Money::solesToMinor((string) $recharge->amount_pen),
                'currency' => 'PEN',
            ]);
        });
    }

    /**
     * Códigos de error 400 oficialmente documentados (búsqueda MCP "create
     * payment error codes 400 401 409 idempotency" — tabla oficial de
     * errores de la API, columna "Code") que son genuinamente errores de
     * REQUEST/INPUT — el payload de ESTE intento es lo que está mal, no la
     * integración. Deliberadamente estrecho: un HTTP 400 cuyo código NO
     * está en esta lista (o que no trae ningún código reconocible en el
     * body) NUNCA se trata como terminal — ver isConfirmedTerminalValidationError().
     *
     * @var string[]
     */
    private const TERMINAL_400_CODES = [
        'json_syntax_error', 'required_properties', 'unsupported_properties',
        'minimum_properties', 'property_type', 'property_value', 'maximum_items',
        'minimum_items', 'invalid_path_param', 'invalid_properties',
        'empty_required_header', 'invalid_idempotency_key_length',
    ];

    /**
     * HTTP ERROR SEMANTICS: clasifica una respuesta NO exitosa de POST
     * /v1/payments contra semántica oficial documentada (búsqueda MCP
     * "create payment error codes 400 401 409 idempotency" — tabla oficial
     * de errores de la API, sección "Idempotency Error"/"Request Error").
     * Nunca `>=400 => failed` — ver docblock de la sección HTTP ERROR
     * SEMANTICS del encargo. NO clasifica solo por status HTTP: el 400
     * además inspecciona el CÓDIGO de error documentado en el body.
     *
     *   - 'validation_error' (400 con un código de la lista confirmada
     *     arriba): CIERTO que no se creó ningún recurso Y el problema es
     *     del payload de este intento — un intento nuevo con datos
     *     corregidos tiene sentido. Un 400 SIN un código reconocido cae en
     *     'unclassified' (fail closed) — nunca se asume terminal por el
     *     solo hecho de ser un 400.
     *   - 'auth_config_error' (401/403/404): error de INTEGRACIÓN/
     *     AUTORIZACIÓN de MOVA (access token inválido, cuenta bloqueada,
     *     endpoint/recurso no encontrado — típicamente un base_url mal
     *     configurado) — NUNCA un rechazo normal del pago (eso es
     *     status=rejected en un 2xx, ver MercadoPagoPaymentStatusMapper).
     *     CIERTO que no se creó nada, pero la causa NO es de este intento —
     *     bloqueo OPERACIONAL (nunca libera un reintento automático del
     *     profesor), registrado 'critical' para que un operador lo note.
     *   - 'idempotency_conflict' (409): documentado explícitamente como
     *     `idempotency_key_already_used` — Mercado Pago CONFIRMA que esa
     *     X-Idempotency-Key ya se usó. Esto NO demuestra por sí solo que el
     *     payment exista (podría ser un conflicto de la propia key), pero
     *     tampoco permite descartarlo — se resuelve exclusivamente vía
     *     recuperación por external_reference, nunca asumiendo ninguna de
     *     las dos cosas. Nunca terminal/failed.
     *   - 'rate_limited' (429): documentado como `usage_quota_exceeded`,
     *     transitorio/retryable (Mercado Pago pide backoff vía
     *     `Retry-After`) — mismo intento, misma key. Nunca asume que el
     *     pago no se creó.
     *   - 'server_error' (5xx): incluye 500 `internal_error` y 500
     *     `idempotency_validation_failed` (este último sugiere una key
     *     nueva, pero SIN confirmar que no se creó nada) — se tratan igual:
     *     incierto, misma key, nunca terminal.
     *   - 'unclassified': cualquier código sin semántica oficialmente
     *     demostrada arriba (incluido un 400 no reconocido) — FAIL CLOSED
     *     como incierto/review, nunca un cobro nuevo automático.
     */
    private function classifyHttpFailure(Response $response): string
    {
        $status = $response->status();

        return match (true) {
            $status === 400 => $this->isConfirmedTerminalValidationError($response) ? 'validation_error' : 'unclassified',
            in_array($status, [401, 403, 404], true) => 'auth_config_error',
            $status === 409 => 'idempotency_conflict',
            $status === 429 => 'rate_limited',
            $status >= 500 && $status < 600 => 'server_error',
            default => 'unclassified',
        };
    }

    private function isConfirmedTerminalValidationError(Response $response): bool
    {
        $code = $this->extractProviderErrorCode($response);

        return $code !== null && in_array($code, self::TERMINAL_400_CODES, true);
    }

    /**
     * Busca un código de error de Mercado Pago en las ubicaciones donde su
     * API lo documenta/reporta (`error`/`code` en la raíz del body, o
     * `cause[].code`/`cause[].error` en el array de causas anidado) — sin
     * asumir una única forma exacta de body, dado que la documentación
     * oficial no expone un ejemplo JSON completo para /v1/payments en
     * particular (solo la tabla de códigos). Devuelve null si no encuentra
     * nada reconocible — el llamador trata eso como "código desconocido",
     * nunca como "sin error" (fail closed, ver isConfirmedTerminalValidationError()).
     */
    private function extractProviderErrorCode(Response $response): ?string
    {
        $body = $response->json();
        if (! is_array($body)) {
            return null;
        }

        foreach (['error', 'code'] as $key) {
            if (isset($body[$key]) && (is_string($body[$key]) || is_int($body[$key]))) {
                return (string) $body[$key];
            }
        }

        $cause = $body['cause'] ?? null;
        if (is_array($cause)) {
            foreach ($cause as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                foreach (['code', 'error'] as $key) {
                    if (isset($entry[$key]) && (is_string($entry[$key]) || is_int($entry[$key]))) {
                        return (string) $entry[$key];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Solo autentica y extrae identificadores — NUNCA la fuente de verdad
     * financiera (ver docblock de la clase). $headers usa claves en
     * minúsculas: 'x-signature', 'x-request-id', y 'data_id_query'.
     *
     * CORREGIDO (ronda de hardening final): el ejemplo oficial actual del
     * tópico `payment` documenta el body con `action`, `api_version`,
     * `data.id`, `date_created`, `id`, `live_mode`, `type`, `user_id` — SIN
     * garantizar `application_id`. La afirmación anterior de que
     * `application_id` siempre viene presente era incorrecta; el chequeo de
     * abajo ya era tolerante a su ausencia (nunca rechazaba solo por
     * faltar), pero este comentario lo dejaba mal descrito. Un webhook
     * `payment` válido y bien formado SIN `application_id` NO es
     * malformed — ver test dedicado.
     *
     *   - `type` debe ser `payment` — este endpoint solo entiende
     *     notificaciones de Payments API (contracargos llegan por un tópico
     *     separado `topic_chargebacks_wh`, ver MercadoPagoPaymentStatusMapper).
     *   - `live_mode` SIEMPRE presente (confirmado, cualquier tópico) — se
     *     valida siempre, sin condición.
     *   - `application_id`: SOLO si aparece Y hay un valor esperado
     *     configurado — puede usarse como chequeo de consistencia adicional,
     *     nunca como requisito de forma.
     *   - `user_id`: validado contra expected_collector_id cuando esté
     *     configurado (mismo criterio condicional).
     *
     * @param  array<string,?string>  $headers
     *
     * @throws MalformedWebhookPayloadException
     */
    public function verifyWebhook(string $rawPayload, array $headers): ?PaymentWebhookEvent
    {
        $secret = config('payments.mercadopago.webhook_secret');
        $dataIdForSignature = $headers['data_id_query'] ?? null;

        if (! $this->signatureVerifier->verify($headers, $dataIdForSignature, $secret)) {
            return null;
        }

        $payload = json_decode($rawPayload, true);
        if (! is_array($payload)) {
            throw new MalformedWebhookPayloadException('Body no es JSON válido.');
        }

        $notificationId = $payload['id'] ?? null;
        $paymentId = $payload['data']['id'] ?? null;

        if (! is_string($notificationId) && ! is_int($notificationId)) {
            throw new MalformedWebhookPayloadException('Falta el id raíz de la notificación.');
        }

        if (! is_string($paymentId) && ! is_int($paymentId)) {
            throw new MalformedWebhookPayloadException('Falta data.id (id del pago) en la notificación.');
        }

        $type = $payload['type'] ?? null;
        if ($type !== null && $type !== 'payment') {
            throw new MalformedWebhookPayloadException(
                "Tópico de notificación inesperado para este endpoint (type={$type}, se esperaba 'payment')."
            );
        }

        $liveMode = $payload['live_mode'] ?? null;
        $expectedLiveMode = self::parseExpectedLiveMode(config('payments.mercadopago.expected_live_mode'));
        if (is_bool($liveMode) && $liveMode !== $expectedLiveMode) {
            throw new MalformedWebhookPayloadException(
                'live_mode de la notificación no coincide con el entorno configurado — posible notificación de otro entorno.'
            );
        }

        $expectedApplicationId = config('payments.mercadopago.application_id');
        $notificationApplicationId = $payload['application_id'] ?? null;
        if ($expectedApplicationId && $notificationApplicationId !== null
            && (string) $notificationApplicationId !== (string) $expectedApplicationId) {
            throw new MalformedWebhookPayloadException(
                'application_id de la notificación no coincide con la aplicación de MOVA configurada.'
            );
        }

        $expectedCollectorId = config('payments.mercadopago.expected_collector_id');
        $notificationUserId = $payload['user_id'] ?? null;
        if ($expectedCollectorId && $notificationUserId !== null
            && (string) $notificationUserId !== (string) $expectedCollectorId) {
            throw new MalformedWebhookPayloadException(
                'user_id de la notificación no coincide con la cuenta vendedora esperada.'
            );
        }

        return new PaymentWebhookEvent(
            eventId: (string) $notificationId,
            eventType: (string) ($payload['action'] ?? $payload['type'] ?? 'unknown'),
            providerOrderId: (string) $paymentId,
            // Hint no autoritativo a propósito — ver docblock de
            // PaymentWebhookEvent y de esta clase. La verdad se resuelve en
            // MercadoPagoPaymentReconciliationService vía fetchPayment().
            status: 'pending',
            amountMinor: null,
            currency: null,
            rawPayload: $payload,
        );
    }

    /**
     * `GET /v1/payments/{id}` — única fuente de verdad financiera. Nunca
     * lanza por un error HTTP/red: devuelve null (fail closed).
     *
     * Campos confirmados contra ejemplos reales de documentación oficial:
     * `id`, `status`, `status_detail`, `currency_id`, `transaction_amount`,
     * `transaction_amount_refunded`, `external_reference`, `collector_id`,
     * `payment_method_id`, `payment_type_id`. El monto BRUTO esperado es
     * siempre `transaction_amount` — nunca `transaction_amount_refunded`
     * (que es cuánto se devolvió, no cuánto se cobró originalmente).
     *
     * @return array{
     *   id:string,status:string,status_detail:?string,transaction_amount:?float,
     *   transaction_amount_refunded:?float,currency_id:?string,
     *   external_reference:?string,collector_id:?string,
     *   payment_method_id:?string,payment_type_id:?string
     * }|null
     */
    public function fetchPayment(string $paymentId): ?array
    {
        $accessToken = config('payments.mercadopago.access_token');
        if (! $accessToken) {
            Log::error('[MercadoPago] fetchPayment sin access_token configurado — fail closed.');

            return null;
        }

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                // GET es seguro de reintentar — a diferencia de POST
                // /v1/payments, nunca puede crear un cobro duplicado.
                ->retry(2, 300, throw: false)
                ->get($this->baseUrl()."/v1/payments/{$paymentId}");
        } catch (\Throwable $e) {
            Log::error('[MercadoPago] Excepción al consultar el pago — fail closed, no credits.', [
                'provider_order_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::error('[MercadoPago] GET payment no exitoso — fail closed, no credits.', [
                'provider_order_id' => $paymentId,
                'status' => $response->status(),
                'error' => $this->safeErrorFromResponse($response),
            ]);

            return null;
        }

        $body = $response->json();
        $mapped = is_array($body) ? $this->mapPaymentJson($body) : null;

        if ($mapped === null) {
            Log::error('[MercadoPago] GET payment 2xx pero sin id/status — respuesta inutilizable, fail closed.', [
                'provider_order_id' => $paymentId,
            ]);

            return null;
        }

        return $mapped;
    }

    /**
     * UNKNOWN PAYMENT RECOVERY: `GET /v1/payments/search?external_reference=`
     * — recuperación DIRIGIDA (nunca un crawler global) de UN intento local
     * incierto (`submission_status='uncertain'`, `provider_order_id IS
     * NULL`) por su external_reference exacto y attempt-specific (ver
     * PaymentOrder::externalReference()) — respuesta oficial confirmada vía
     * MCP: `{paging:{total,limit,offset}, results:[...]}`, cada elemento con
     * la misma forma que `GET /v1/payments/{id}`.
     *
     * Nunca lanza por error HTTP/red: devuelve null (fail closed, "sigue
     * incierto" — el llamador NO debe tratar null como "cero resultados").
     * Un array vacío `[]` SÍ es un resultado válido y distinto: "Mercado
     * Pago confirma que no existe ningún pago con esa referencia todavía".
     *
     * @return array<int,array{id:string,status:string,status_detail:?string,transaction_amount:?float,transaction_amount_refunded:?float,currency_id:?string,external_reference:?string,collector_id:?string,payment_method_id:?string,payment_type_id:?string}>|null
     */
    public function searchPaymentsByExternalReference(string $externalReference): ?array
    {
        $accessToken = config('payments.mercadopago.access_token');
        if (! $accessToken) {
            Log::error('[MercadoPago] searchPaymentsByExternalReference sin access_token configurado — fail closed.');

            return null;
        }

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                // GET es seguro de reintentar — nunca puede crear un cobro.
                ->retry(2, 300, throw: false)
                ->get($this->baseUrl().'/v1/payments/search', [
                    'external_reference' => $externalReference,
                    'limit' => 10,
                ]);
        } catch (Throwable $e) {
            Log::error('[MercadoPago] Excepción al buscar por external_reference — fail closed, sigue incierto.', [
                'external_reference' => $externalReference,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::error('[MercadoPago] GET /v1/payments/search no exitoso — fail closed, sigue incierto.', [
                'external_reference' => $externalReference,
                'status' => $response->status(),
                'error' => $this->safeErrorFromResponse($response),
            ]);

            return null;
        }

        $results = $response->json('results');
        if (! is_array($results)) {
            Log::error('[MercadoPago] GET /v1/payments/search 2xx pero sin "results" utilizable — fail closed.', [
                'external_reference' => $externalReference,
            ]);

            return null;
        }

        $mapped = [];
        foreach ($results as $raw) {
            $item = is_array($raw) ? $this->mapPaymentJson($raw) : null;
            if ($item === null) {
                // Un elemento malformado hace toda la respuesta inutilizable
                // — nunca se filtra en silencio (podría ocultar exactamente
                // el resultado que hacía la búsqueda ambigua).
                Log::error('[MercadoPago] GET /v1/payments/search devolvió un elemento sin id/status — respuesta inutilizable, fail closed.', [
                    'external_reference' => $externalReference,
                ]);

                return null;
            }

            $mapped[] = $item;
        }

        return $mapped;
    }

    /**
     * Extrae los campos financieros relevantes de un payment JSON crudo de
     * Mercado Pago — compartido por fetchPayment() y
     * searchPaymentsByExternalReference() para que ambos expongan
     * exactamente la misma forma a MercadoPagoPaymentReconciliationService.
     * Devuelve null si `id`/`status` no están utilizables (fail closed).
     *
     * @param  array<string,mixed>  $payment
     * @return array{id:string,status:string,status_detail:?string,transaction_amount:?float,transaction_amount_refunded:?float,currency_id:?string,external_reference:?string,collector_id:?string,payment_method_id:?string,payment_type_id:?string}|null
     */
    private function mapPaymentJson(array $payment): ?array
    {
        $id = $payment['id'] ?? null;
        $status = $payment['status'] ?? null;

        if ($id === null || ! is_string($status)) {
            return null;
        }

        return [
            'id' => (string) $id,
            'status' => $status,
            'status_detail' => $payment['status_detail'] ?? null,
            'transaction_amount' => $payment['transaction_amount'] ?? null,
            'transaction_amount_refunded' => $payment['transaction_amount_refunded'] ?? null,
            'currency_id' => $payment['currency_id'] ?? null,
            'external_reference' => $payment['external_reference'] ?? null,
            'collector_id' => isset($payment['collector_id']) ? (string) $payment['collector_id'] : null,
            'payment_method_id' => $payment['payment_method_id'] ?? null,
            'payment_type_id' => $payment['payment_type_id'] ?? null,
        ];
    }

    /**
     * GET /v1/payment_methods — igual que antes, solo requiere access_token
     * (nunca el webhook secret — ver config/payments.php). Usado por
     * mercadopago:diagnose.
     *
     * @return array<int,array{id:?string,name:?string,payment_type_id:?string,status:?string}>|null
     */
    public function fetchPaymentMethods(): ?array
    {
        $accessToken = config('payments.mercadopago.access_token');
        if (! $accessToken) {
            return null;
        }

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->retry(2, 300, throw: false)
                ->get($this->baseUrl().'/v1/payment_methods');
        } catch (\Throwable $e) {
            Log::error('[MercadoPago] Excepción al consultar payment_methods.', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful() || ! is_array($response->json())) {
            Log::error('[MercadoPago] GET payment_methods no exitoso.', [
                'status' => $response->status(),
                'error' => $this->safeErrorFromResponse($response),
            ]);

            return null;
        }

        return array_map(
            static fn (array $method) => [
                'id' => $method['id'] ?? null,
                'name' => $method['name'] ?? null,
                'payment_type_id' => $method['payment_type_id'] ?? null,
                'status' => $method['status'] ?? null,
            ],
            $response->json()
        );
    }

    /**
     * Arma la porción del payload específica del medio de pago — el único
     * lugar donde `payment_method_id`/`installments` se deciden. Para Yape
     * están FORZADOS server-side (nunca leídos de una request del
     * frontend): YapePaymentInstrument ni siquiera tiene esos campos, así
     * que no hay forma de que el frontend "convierta" un pago Yape en otro
     * medio de pago.
     *
     * @return array<string,mixed>
     */
    private function instrumentPayload(TokenizedPaymentInstrument $instrument): array
    {
        if ($instrument instanceof YapePaymentInstrument) {
            return [
                'token' => $instrument->token,
                'payment_method_id' => 'yape',
                'installments' => 1,
            ];
        }

        if ($instrument instanceof CardPaymentInstrument) {
            $payload = [
                'token' => $instrument->token,
                'payment_method_id' => $instrument->paymentMethodId,
                'installments' => $instrument->installments,
            ];

            if ($instrument->issuerId !== null) {
                $payload['issuer_id'] = $instrument->issuerId;
            }

            if ($instrument->identificationType !== null && $instrument->identificationNumber !== null) {
                $payload['identification'] = [
                    'type' => $instrument->identificationType,
                    'number' => $instrument->identificationNumber,
                ];
            }

            return $payload;
        }

        // Nunca debería llegar aquí (PaymentMethodKind es exhaustivo) — fail
        // closed explícito en vez de silenciosamente ignorar un kind nuevo.
        throw new RuntimeException('MercadoPagoPaymentProvider: TokenizedPaymentInstrument no soportado: '.get_class($instrument));
    }

    /**
     * Convierte centavos enteros a un NÚMERO (no string) para el payload
     * de transporte — Payments API documenta transaction_amount como
     * número JSON. round() a 2 decimales antes de exponerlo evita ruido de
     * punto flotante en la serialización; la comparación de verdad sigue
     * ocurriendo siempre en centavos enteros (ver fetchPayment() y
     * MercadoPagoPaymentReconciliationService), nunca comparando este float.
     */
    private function amountMinorToApiNumber(int $amountMinor): float
    {
        return round($amountMinor / 100, 2);
    }

    /**
     * `expected_live_mode` ya NO tiene un default silencioso en config()
     * (antes `(bool) env(..., false)` — cualquier valor ausente se leía
     * como TEST sin que nadie lo hubiera decidido explícitamente). Ahora
     * config() guarda el valor crudo (string 'true'/'false' o null) y
     * ProviderGuard::requireConfig() ya exige que no esté vacío cuando
     * Mercado Pago está habilitado — este helper solo interpreta el string
     * de forma robusta en el único punto donde se usa.
     */
    private static function parseExpectedLiveMode(mixed $raw): bool
    {
        return filter_var((string) $raw, FILTER_VALIDATE_BOOLEAN);
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('payments.mercadopago.base_url', 'https://api.mercadopago.com'), '/');
    }

    /**
     * Igual criterio que MetaCloudApiProvider::safeErrorFromResponse() — el
     * logging nunca debe lanzar por un body no-JSON, y nunca se registra el
     * body crudo completo sin truncar.
     */
    private function safeErrorFromResponse($response): string
    {
        try {
            $error = $response->json('message') ?? $response->json('error');

            return $error ? json_encode($error) : ('respuesta no-JSON: '.\Illuminate\Support\Str::limit($response->body(), 200));
        } catch (\Throwable) {
            return 'no se pudo interpretar la respuesta de Mercado Pago';
        }
    }
}
