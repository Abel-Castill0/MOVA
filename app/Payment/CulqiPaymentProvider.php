<?php

namespace App\Payment;

use App\Models\PaymentOrder;
use App\Models\RechargeRequest;
use App\Payment\Contracts\CardPaymentInstrument;
use App\Payment\Contracts\PaymentMethodKind;
use App\Payment\Contracts\PaymentProviderContract;
use App\Payment\Contracts\TokenizedPaymentInstrument;
use App\Payment\Contracts\YapePaymentInstrument;
use App\Payment\Culqi\CulqiClient;
use App\Payment\Culqi\CulqiConfig;
use App\Payment\Culqi\CulqiNotSentException;
use App\Payment\Culqi\CulqiResult;
use App\Payment\Culqi\CulqiTokenInstrument;
use App\Payment\Culqi\MalformedCulqiWebhookException;
use App\Services\CulqiPaymentReconciliationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Culqi API V2 — cargos con token de tarjeta/Yape (POST /v2/charges) y órdenes de
 * pago (POST /v2/orders: PagoEfectivo, billeteras, Cuotéalo…).
 *
 * Invariantes (probadas en tests/Feature/CulqiPaymentTest.php y CulqiCheckoutTest.php):
 *  - Importe, moneda y email salen SIEMPRE de la RechargeRequest en el
 *    servidor; el instrumento solo aporta el token (source_id), que Culqi
 *    entrega ya tokenizado — ningún PAN/CVV toca este backend ni sus logs.
 *  - Un PaymentOrder por intento, creado ANTES de llamar a Culqi, con un
 *    `idempotency_key` propio que viaja en metadata del cargo/orden. Culqi no
 *    documenta cabecera de idempotencia, así que la protección contra doble
 *    cobro es: (1) transición atómica prepared→submitting (una sola petición
 *    gana), (2) el POST NUNCA se reintenta, (3) un resultado incierto
 *    (timeout/5xx) deja el intento en 'uncertain' y bloquea nuevos cobros
 *    hasta que la reconciliación lo resuelva consultando la API, y (4) en el
 *    sandbox reusar un token tras una venta exitosa fue denegado por Culqi.
 *  - 3DS (documentación Culqi 3DS): un 200 con `action_code` en el primer
 *    POST /v2/charges NO crea cargo; el intento queda 'awaiting_3ds'. El
 *    segundo POST (con authentication_3DS) solo se acepta si el token y el
 *    device_finger_print_id coinciden con los del primero (se comparan por
 *    hash) y la ventana no venció. Nunca hay dos cargos "vivos" a la vez.
 *  - Crear el cargo NO acredita nada por sí mismo: el resultado pasa por
 *    CulqiPaymentReconciliationService (contexto + importe + modo) y de ahí
 *    por RechargeApprovalService::credit() (exactamente-una-vez). Una orden
 *    pendiente tampoco acredita: solo `state=paid` confirmado por la API.
 */
class CulqiPaymentProvider implements PaymentProviderContract
{
    public const PROVIDER = 'culqi';

    public const CHANNEL_CARD = 'card';
    public const CHANNEL_YAPE = 'yape';
    public const CHANNEL_ORDER = 'order';

    public function __construct(
        private readonly ?CulqiClient $client = null,
        private readonly ?CulqiPaymentReconciliationService $reconciler = null,
    ) {
    }

    public function createPaymentAttempt(RechargeRequest $recharge, ?TokenizedPaymentInstrument $instrument = null): PaymentOrder
    {
        CulqiConfig::assertConsistent();

        [$token, $kind, $device] = $this->describe($instrument);
        $email = $this->payerEmail($recharge);

        // Se resuelve ANTES del claim: si la configuración del cliente (p. ej. cifrado RSA) es
        // inválida, falla sin dejar un intento atascado en 'submitting'.
        $client = $this->client();

        $order = $this->resolveAttemptRow($recharge);

        if (in_array($order->submission_status, ['submitting', 'uncertain'], true)) {
            $outcome = $this->reconciler()->reconcileUncertainSubmission($order);

            throw new RuntimeException(
                "CulqiPaymentProvider: RechargeRequest#{$recharge->id} tiene un intento previo con resultado incierto "
                ."sin resolver (verificación: {$outcome}) — no se envía un cobro nuevo automáticamente."
            );
        }

        // Reclamo atómico: solo UNA petición concurrente pasa de 'prepared' a 'submitting'.
        $claimed = PaymentOrder::whereKey($order->id)
            ->where('submission_status', 'prepared')
            ->update([
                'submission_status' => 'submitting',
                'payment_channel' => $kind === PaymentMethodKind::Yape ? self::CHANNEL_YAPE : self::CHANNEL_CARD,
                'source_token_hash' => hash('sha256', $token),
                'device_fingerprint_id' => $device,
            ]);

        if ($claimed !== 1) {
            throw new RuntimeException('CulqiPaymentProvider: otro proceso ya está enviando este intento — no se duplica el cobro.');
        }

        return $this->submitCharge($client, $order->fresh(), $recharge, $email, $token, $device, null);
    }

    /**
     * Segundo paso de 3DS: el navegador completó la autenticación con Culqi3DS y entrega los
     * `parameters3DS`. Solo avanza un intento 'awaiting_3ds' vigente, del MISMO token y device.
     */
    public function completeThreeDs(RechargeRequest $recharge, CulqiTokenInstrument $instrument): PaymentOrder
    {
        CulqiConfig::assertConsistent();

        [$token, , $device] = $this->describe($instrument);
        $params = $this->validThreeDsParameters($instrument->threeDs);
        $email = $this->payerEmail($recharge);
        $client = $this->client();

        $order = DB::transaction(function () use ($recharge, $token, $device) {
            $latest = $this->lockedLatest($recharge);

            if ($latest === null || $latest->submission_status !== 'awaiting_3ds') {
                throw new RuntimeException('CulqiPaymentProvider: no hay una autenticación 3DS pendiente para esta recarga.');
            }
            if ($latest->three_ds_expires_at === null || $latest->three_ds_expires_at->isPast()) {
                $this->closeAwaitingThreeDs($latest, '3ds_expired');

                throw new RuntimeException('CulqiPaymentProvider: la ventana de la autenticación 3DS venció; inicia un intento nuevo.');
            }
            if (! hash_equals((string) $latest->source_token_hash, hash('sha256', $token)) || ! hash_equals((string) $latest->device_fingerprint_id, (string) $device)) {
                throw new RuntimeException('CulqiPaymentProvider: el token o el dispositivo no coinciden con los del intento original (3DS).');
            }

            $claimed = PaymentOrder::whereKey($latest->id)->where('submission_status', 'awaiting_3ds')->update(['submission_status' => 'submitting']);
            if ($claimed !== 1) {
                throw new RuntimeException('CulqiPaymentProvider: otro proceso ya está completando este 3DS — no se duplica el cobro.');
            }

            return $latest->fresh();
        });

        return $this->submitCharge($client, $order, $recharge, $email, $token, $device, $params);
    }

    /** El usuario canceló o falló el reto 3DS: no existe cargo, el intento se cierra y puede reintentar. */
    public function abandonThreeDs(RechargeRequest $recharge, string $reason = '3ds_cancelled'): PaymentOrder
    {
        return DB::transaction(function () use ($recharge, $reason) {
            $latest = $this->lockedLatest($recharge);

            if ($latest === null || $latest->submission_status !== 'awaiting_3ds') {
                throw new RuntimeException('CulqiPaymentProvider: no hay una autenticación 3DS pendiente que cerrar.');
            }

            return $this->closeAwaitingThreeDs($latest, $reason);
        });
    }

    /**
     * Orden de pago (PagoEfectivo, billeteras móviles, banca móvil, agentes, Cuotéalo): se crea la
     * orden en Culqi con confirm=false y el cliente elige el medio en el checkout de Culqi. NO
     * acredita nada: solo `state=paid` confirmado por la API (webhook order.status.changed o
     * conciliación) lo hace.
     */
    public function createOrderAttempt(RechargeRequest $recharge): PaymentOrder
    {
        CulqiConfig::assertConsistent();

        $user = $recharge->teacherProfile->user ?? null;
        $email = $this->payerEmail($recharge);
        $phone = $this->phoneDigits($user?->phone);
        if ($phone === null) {
            throw new RuntimeException('CulqiPaymentProvider: Culqi exige un celular válido (9 dígitos) del pagador para crear una orden; el profesor debe registrarlo en su perfil.');
        }

        $client = $this->client();
        $order = $this->resolveAttemptRow($recharge);

        if (in_array($order->submission_status, ['submitting', 'uncertain'], true)) {
            throw new RuntimeException('CulqiPaymentProvider: hay una creación de orden anterior con resultado incierto; se resolverá antes de permitir otra.');
        }

        $claimed = PaymentOrder::whereKey($order->id)->where('submission_status', 'prepared')
            ->update(['submission_status' => 'submitting', 'payment_channel' => self::CHANNEL_ORDER]);
        if ($claimed !== 1) {
            throw new RuntimeException('CulqiPaymentProvider: otro proceso ya está creando esta orden.');
        }
        $order = $order->fresh();

        $expiresAt = now()->addMinutes(max(30, (int) config('payments.culqi.order_ttl_minutes', 1440)));
        [$first, $last] = $this->splitName((string) ($user?->name ?? ''));

        try {
            $result = $client->createOrder([
                'amount' => $order->amount_minor,
                'currency_code' => $order->currency,
                'description' => 'MOVA recarga de creditos',
                'order_number' => $this->orderNumber($order),
                'expiration_date' => $expiresAt->timestamp,
                'client_details' => ['first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone_number' => $phone],
                'confirm' => false,
                'metadata' => $this->metadata($order, $recharge),
            ]);
        } catch (CulqiNotSentException $e) {
            PaymentOrder::whereKey($order->id)->where('submission_status', 'submitting')->update(['submission_status' => 'prepared']);

            throw new RuntimeException('CulqiPaymentProvider: la petición no pudo enviarse (configuración de cifrado inválida). No se creó nada.', previous: $e);
        }

        if ($result->uncertain()) {
            // Una orden sin pagar no mueve dinero; si existió y se paga, el webhook la vincula por metadata.
            $order->update(['submission_status' => 'uncertain', 'creation_http_status' => $result->status ?: null, 'provider_status' => $result->transportError ? 'transport_error' : 'http_'.$result->status]);

            throw new RuntimeException('CulqiPaymentProvider: resultado incierto al crear la orden; no se reintenta automáticamente.');
        }

        $id = $result->body['id'] ?? null;
        if (! $result->successful() || ! is_string($id) || ! str_starts_with($id, 'ord_')) {
            return $this->failAttempt($order, $result, $result->errorCode() ?? 'order_rejected');
        }

        $order->update([
            'provider_order_id' => $id,
            'status' => 'pending',
            'submission_status' => 'submitted',
            'expires_at' => $expiresAt,
            'creation_provider_request_id' => $this->trim($result->trackingId, 100),
        ]);

        return $order->fresh();
    }

    public function verifyWebhook(string $rawPayload, array $headers): ?PaymentWebhookEvent
    {
        $given = $headers['x-mova-webhook-token'] ?? null;

        // Culqi no firma sus notificaciones: el único "secreto" es el valor que MOVA
        // incluye en la URL registrada en CulqiPanel. Comparación en tiempo constante.
        // Un secreto corto (< MIN_WEBHOOK_SECRET_LENGTH) nunca autentica: sería adivinable por fuerza bruta.
        // Durante una rotación también vale el secreto anterior (CULQI_WEBHOOK_SECRET_PREVIOUS).
        $authenticated = false;
        if (is_string($given)) {
            foreach ([config('payments.culqi.webhook_secret'), config('payments.culqi.webhook_secret_previous')] as $candidate) {
                if (is_string($candidate) && strlen($candidate) >= CulqiConfig::MIN_WEBHOOK_SECRET_LENGTH && hash_equals($candidate, $given)) {
                    $authenticated = true;
                }
            }
        }
        if (! $authenticated) {
            return null;
        }

        $envelope = json_decode($rawPayload, true);
        if (! is_array($envelope) || ! is_string($envelope['id'] ?? null) || ! is_string($envelope['type'] ?? null)) {
            throw new MalformedCulqiWebhookException('Notificación de Culqi sin id/type.');
        }

        // `data` llega como string JSON (esquema NotifyEvent); aceptamos también un objeto.
        $data = $envelope['data'] ?? null;
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        if (! is_array($data)) {
            $data = [];
        }

        $type = $envelope['type'];
        $objectId = is_string($data['id'] ?? null) ? $data['id'] : null;

        // El tipo del evento solo da un HINT; la verdad la decide la reconciliación contra la API.
        $status = match (true) {
            $type === 'charge.creation.succeeded' => 'paid',
            $type === 'charge.creation.failed' => 'failed',
            str_starts_with($type, 'refund.creation') => 'review',
            default => 'pending',
        };

        // Se persiste solo lo necesario para auditar y reconciliar (ids y tipo): el cuerpo completo de
        // un cargo/orden trae datos del pagador (correo, teléfono, BIN, antifraude) que no hace falta retener.
        $minimal = [
            'id' => $envelope['id'],
            'type' => $type,
            'creation_date' => $envelope['creation_date'] ?? null,
            'data' => array_filter([
                'id' => $objectId,
                'object' => is_string($data['object'] ?? null) ? $data['object'] : null,
                'charge_id' => is_string($data['charge_id'] ?? null) ? $data['charge_id'] : null,
            ], static fn ($v) => $v !== null),
        ];

        return new PaymentWebhookEvent(
            eventId: $envelope['id'],
            eventType: $type,
            providerOrderId: $this->resourceIdFrom($type, $data, $objectId),
            status: $status,
            amountMinor: isset($data['amount']) && is_numeric($data['amount']) ? (int) $data['amount'] : null,
            currency: is_string($data['currency_code'] ?? null) ? $data['currency_code'] : null,
            rawPayload: $minimal,
        );
    }

    // ── Cargos ────────────────────────────────────────────────────────────

    /** @param array<string, mixed>|null $threeDs */
    private function submitCharge(CulqiClient $client, PaymentOrder $order, RechargeRequest $recharge, string $email, string $token, ?string $device, ?array $threeDs): PaymentOrder
    {
        $payload = [
            'amount' => $order->amount_minor,
            'currency_code' => $order->currency,
            'email' => $email,
            'source_id' => $token,
            'capture' => true,
            'description' => 'MOVA recarga de creditos',
            'metadata' => $this->metadata($order, $recharge),
        ];

        $antifraud = $this->antifraudDetails($recharge, $device);
        if ($antifraud !== []) {
            $payload['antifraud_details'] = $antifraud;
        }
        if ($threeDs !== null) {
            $payload['authentication_3DS'] = $threeDs;
        }

        try {
            $result = $client->createCharge($payload);
        } catch (CulqiNotSentException $e) {
            // Se sabe con certeza que no salió nada hacia Culqi: el intento vuelve a su estado previo.
            PaymentOrder::whereKey($order->id)->where('submission_status', 'submitting')
                ->update(['submission_status' => $threeDs !== null ? 'awaiting_3ds' : 'prepared']);

            throw new RuntimeException('CulqiPaymentProvider: la petición no pudo enviarse (configuración de cifrado inválida). No se cobró nada.', previous: $e);
        }

        return $this->handleCreateResult($order, $result, $threeDs !== null);
    }

    private function handleCreateResult(PaymentOrder $order, CulqiResult $result, bool $wasThreeDsStep = false): PaymentOrder
    {
        if ($result->uncertain()) {
            Log::error('[Culqi] Resultado incierto al crear el cargo — nunca se asume que no se creó y no se reintenta.', [
                'payment_order_id' => $order->id,
                'http_status' => $result->status,
                'transport' => $result->transportError,
                'tracking_id' => $result->trackingId,
            ]);

            $order->update([
                'submission_status' => 'uncertain',
                'creation_http_status' => $result->status ?: null,
                'creation_provider_request_id' => $this->trim($result->trackingId, 100),
                'provider_status' => $result->transportError ? 'transport_error' : 'http_'.$result->status,
            ]);

            throw new RuntimeException(
                'CulqiPaymentProvider: resultado incierto al crear el cargo (sin confirmación de Culqi). '
                .'No se reintenta; la reconciliación lo resolverá consultando la API antes de permitir un cobro nuevo.'
            );
        }

        // 200 con action_code (documentación Culqi 3DS: el motor antifraude pide AUTENTICAR): NO es un cargo.
        if ($result->status === 200 && isset($result->body['action_code'])) {
            $canAuthenticate = ! $wasThreeDsStep
                && $order->payment_channel === self::CHANNEL_CARD
                && filled($order->device_fingerprint_id);

            if (! $canAuthenticate) {
                // Segundo intento que vuelve a pedir 3DS, Yape o sin device: no hay bucle, el intento termina.
                return $this->failAttempt($order, $result, 'requires_3ds');
            }

            $order->update([
                'status' => 'pending',
                'submission_status' => 'awaiting_3ds',
                'three_ds_expires_at' => now()->addMinutes(max(2, (int) config('payments.culqi.three_ds_window_minutes', 8))),
                'provider_status' => 'http_200',
                'provider_status_detail' => 'requires_3ds',
                'creation_provider_request_id' => $this->trim($result->trackingId, 100),
            ]);

            return $order->fresh();
        }

        if (! $result->successful()) {
            // Error definitivo y documentado (objeto `error` con 4xx): la operación NO se realizó.
            $category = $result->status === 401 || $result->status === 403 ? 'auth_config_error' : 'rejected';
            $this->failAttempt($order, $result, $result->errorCode() ?? $category);

            if ($category === 'auth_config_error') {
                $this->reconciler()->markReview($order->fresh(), null, "Culqi rechazó las credenciales de MOVA (HTTP {$result->status}) — requiere revisión operacional.");
            }

            return $order->fresh();
        }

        $charge = $result->body;
        $chargeId = $charge['id'] ?? null;
        if (! is_string($chargeId) || $chargeId === '') {
            $order->update(['submission_status' => 'uncertain', 'provider_status' => 'http_'.$result->status.'_sin_id']);
            throw new RuntimeException('CulqiPaymentProvider: Culqi respondió 2xx sin id de cargo — resultado incierto.');
        }

        $order->update([
            'provider_order_id' => $chargeId,
            'status' => 'pending',
            'submission_status' => 'submitted',
            'three_ds_expires_at' => null,
            'creation_provider_request_id' => $this->trim($result->trackingId, 100),
        ]);

        // La respuesta del POST es verdad server-to-server: se valida el contexto igual que un GET.
        $this->reconciler()->applyTruth($order->fresh(), null, $charge);

        return $order->fresh();
    }

    private function failAttempt(PaymentOrder $order, CulqiResult $result, string $detail): PaymentOrder
    {
        // Evidencia de sandbox: un rechazo (HTTP 400) devuelve el `charge_id` del cargo denegado,
        // que Culqi sí registra. Se guarda para trazabilidad y para que `charge.creation.failed`
        // y la reconciliación lo encuentren (UNIQUE provider+provider_order_id).
        $chargeId = $result->body['charge_id'] ?? null;
        if (! is_string($chargeId) || $chargeId === ''
            || PaymentOrder::where('provider', self::PROVIDER)->where('provider_order_id', $chargeId)->where('id', '!=', $order->id)->exists()) {
            $chargeId = null; // sin id, o ya asociado a otro intento: no se pisa (UNIQUE provider+provider_order_id)
        }

        $order->update([
            'provider_order_id' => $chargeId ?? $order->provider_order_id,
            'status' => 'failed',
            'submission_status' => null,
            'three_ds_expires_at' => null,
            'creation_http_status' => $result->status,
            'creation_error_codes' => $this->trim($detail, 191),
            'creation_provider_request_id' => $this->trim($result->trackingId, 100),
            'creation_failed_at' => now(),
            'provider_status' => 'http_'.$result->status,
            'provider_status_detail' => $this->trim($detail, 64),
        ]);

        Log::info('[Culqi] Cargo rechazado de forma definitiva.', [
            'payment_order_id' => $order->id,
            'http_status' => $result->status,
            'codigo' => $detail,
            'tipo' => $result->errorType(),
        ]);

        return $order->fresh();
    }

    private function closeAwaitingThreeDs(PaymentOrder $order, string $reason): PaymentOrder
    {
        $order->update([
            'status' => 'failed',
            'submission_status' => null,
            'three_ds_expires_at' => null,
            'provider_status_detail' => $this->trim($reason, 64),
        ]);

        return $order->fresh();
    }

    // ── Intentos ──────────────────────────────────────────────────────────

    private function lockedLatest(RechargeRequest $recharge): ?PaymentOrder
    {
        RechargeRequest::whereKey($recharge->id)->lockForUpdate()->firstOrFail();

        return PaymentOrder::where('recharge_request_id', $recharge->id)
            ->where('provider', self::PROVIDER)
            ->orderByDesc('attempt_number')
            ->lockForUpdate()
            ->first();
    }

    private function resolveAttemptRow(RechargeRequest $recharge): PaymentOrder
    {
        return DB::transaction(function () use ($recharge) {
            $locked = RechargeRequest::whereKey($recharge->id)->lockForUpdate()->firstOrFail();

            // Solo una recarga automática de Culqi que siga pendiente puede cobrarse: de lo contrario
            // (p. ej. una manual ya rechazada) el cobro quedaría sin poder acreditarse.
            if ($locked->payment_method !== self::PROVIDER || $locked->status !== 'pending') {
                throw new RuntimeException(
                    "CulqiPaymentProvider: RechargeRequest#{$recharge->id} no admite un cobro (método '{$locked->payment_method}', estado '{$locked->status}')."
                );
            }

            $latest = PaymentOrder::where('recharge_request_id', $recharge->id)
                ->orderByDesc('attempt_number')
                ->lockForUpdate()
                ->first();

            // Un 3DS sin completar no tiene cargo: vencida su ventana, se cierra y se admite un intento nuevo.
            if ($latest !== null && $latest->submission_status === 'awaiting_3ds') {
                if ($latest->three_ds_expires_at !== null && $latest->three_ds_expires_at->isPast()) {
                    $latest = $this->closeAwaitingThreeDs($latest, '3ds_expired');
                } else {
                    throw new RuntimeException('CulqiPaymentProvider: hay una autenticación 3DS en curso para esta recarga; complétala o cancélala antes de reintentar.');
                }
            }

            if ($latest !== null && in_array($latest->submission_status, ['prepared', 'submitting', 'uncertain'], true)) {
                return $latest;
            }

            if ($latest !== null && ! in_array($latest->status, ['failed', 'cancelled', 'expired'], true)) {
                throw new RuntimeException(
                    "CulqiPaymentProvider: RechargeRequest#{$recharge->id} ya tiene un intento en estado '{$latest->status}' "
                    .'— no se crea uno nuevo mientras ese siga activo o pagado.'
                );
            }

            return PaymentOrder::create([
                'recharge_request_id' => $recharge->id,
                'attempt_number' => $latest ? $latest->attempt_number + 1 : 1,
                'idempotency_key' => (string) Str::uuid(),
                'provider' => self::PROVIDER,
                'provider_order_id' => null,
                'submission_status' => 'prepared',
                'status' => 'pending',
                'amount_minor' => Money::solesToMinor((string) $recharge->amount_pen),
                'currency' => 'PEN',
            ]);
        });
    }

    // ── Utilidades ────────────────────────────────────────────────────────

    /** @return array{0:string,1:PaymentMethodKind,2:?string} token, tipo y device_fingerprint_id */
    private function describe(?TokenizedPaymentInstrument $instrument): array
    {
        if ($instrument === null) {
            throw new RuntimeException('CulqiPaymentProvider: falta un token de Culqi (Checkout/Culqi.js o Yape); POST /v2/charges no puede armarse sin source_id.');
        }

        if ($instrument instanceof CulqiTokenInstrument) {
            $token = $instrument->token;
            $device = $instrument->deviceFingerprintId;
        } elseif ($instrument instanceof CardPaymentInstrument || $instrument instanceof YapePaymentInstrument) {
            $token = $instrument->token;
            $device = null;
        } else {
            throw new RuntimeException('CulqiPaymentProvider: medio de pago no soportado (solo token de tarjeta o de Yape).');
        }

        if (! preg_match('/^[a-z]{3}_(test|live)_[A-Za-z0-9]+$/', $token) || ! str_contains($token, '_'.CulqiConfig::declaredEnv().'_')) {
            throw new RuntimeException('CulqiPaymentProvider: el token de pago no tiene el formato de Culqi o es de otro entorno (test/live).');
        }

        if ($device !== null && ! preg_match('/^[0-9a-fA-F-]{16,64}$/', $device)) {
            throw new RuntimeException('CulqiPaymentProvider: device_fingerprint_id inválido.');
        }

        $kind = str_starts_with($token, 'ype_') ? PaymentMethodKind::Yape : PaymentMethodKind::Card;

        return [$token, $kind, $device];
    }

    private function payerEmail(RechargeRequest $recharge): string
    {
        $email = $recharge->teacherProfile->user->email ?? null;
        if (! is_string($email) || $email === '') {
            throw new RuntimeException('CulqiPaymentProvider: la recarga no tiene un correo de pagador verificable.');
        }

        return $email;
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array{eci:string,xid:string,cavv:string,protocolVersion:string,directoryServerTransactionId?:string}
     */
    private function validThreeDsParameters(?array $raw): array
    {
        $ok = is_array($raw)
            && is_string($raw['eci'] ?? null) && preg_match('/^\d{2}$/', $raw['eci'])
            && is_string($raw['xid'] ?? null) && preg_match('/^[A-Za-z0-9+\/=_-]{8,128}$/', $raw['xid'])
            && is_string($raw['cavv'] ?? null) && preg_match('/^[A-Za-z0-9+\/=_-]{8,128}$/', $raw['cavv'])
            && is_string($raw['protocolVersion'] ?? null) && preg_match('/^\d\.\d\.\d$/', $raw['protocolVersion'])
            && (! isset($raw['directoryServerTransactionId']) || (is_string($raw['directoryServerTransactionId']) && preg_match('/^[0-9a-fA-F-]{16,64}$/', $raw['directoryServerTransactionId'])));

        if (! $ok) {
            throw new RuntimeException('CulqiPaymentProvider: los parámetros 3DS no tienen la forma documentada por Culqi.');
        }

        return array_filter([
            'eci' => $raw['eci'],
            'xid' => $raw['xid'],
            'cavv' => $raw['cavv'],
            'protocolVersion' => $raw['protocolVersion'],
            'directoryServerTransactionId' => $raw['directoryServerTransactionId'] ?? null,
        ], static fn ($v) => $v !== null);
    }

    /** @return array<string, string> */
    private function metadata(PaymentOrder $order, RechargeRequest $recharge): array
    {
        return [
            'payment_order_id' => (string) $order->id,
            'recharge_request_id' => (string) $recharge->id,
            'attempt' => (string) $order->attempt_number,
            'idempotency_key' => (string) $order->idempotency_key,
        ];
    }

    /** @return array<string, string> */
    private function antifraudDetails(RechargeRequest $recharge, ?string $device): array
    {
        $user = $recharge->teacherProfile->user ?? null;
        [$first, $last] = $this->splitName((string) ($user?->name ?? ''));
        $phone = $this->phoneDigits($user?->phone);

        return array_filter([
            'first_name' => $first,
            'last_name' => $last,
            'phone_number' => $phone,
            'device_finger_print_id' => $device,
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /** @return array{0:string,1:string} */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];
        $first = mb_substr($parts[0] ?? 'Profesor', 0, 50);
        $last = mb_substr($parts[1] ?? 'MOVA', 0, 50);

        return [$first !== '' ? $first : 'Profesor', $last !== '' ? $last : 'MOVA'];
    }

    private function phoneDigits(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        $digits = strlen($digits) > 9 ? substr($digits, -9) : $digits;

        return strlen($digits) === 9 ? $digits : null;
    }

    private function orderNumber(PaymentOrder $order): string
    {
        return mb_substr("mova-r{$order->recharge_request_id}-a{$order->attempt_number}-".substr(md5((string) $order->idempotency_key), 0, 6), 0, 36);
    }

    /** Un evento de devolución trae el id del reembolso; el cargo viene en `charge_id`. */
    private function resourceIdFrom(string $type, array $data, ?string $objectId): ?string
    {
        if (str_starts_with($type, 'refund.')) {
            return is_string($data['charge_id'] ?? null) ? $data['charge_id'] : null;
        }

        return str_starts_with($type, 'charge.') || str_starts_with($type, 'order.') ? $objectId : null;
    }

    private function trim(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $max);
    }

    private function client(): CulqiClient
    {
        return $this->client ?? app(CulqiClient::class);
    }

    private function reconciler(): CulqiPaymentReconciliationService
    {
        return $this->reconciler ?? app(CulqiPaymentReconciliationService::class);
    }
}
