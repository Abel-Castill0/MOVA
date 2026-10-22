<?php

namespace App\Payment;

use App\Models\PaymentOrder;
use App\Models\RechargeRequest;
use App\Payment\Contracts\CardPaymentInstrument;
use App\Payment\Contracts\PaymentProviderContract;
use App\Payment\Contracts\TokenizedPaymentInstrument;
use App\Payment\Contracts\YapePaymentInstrument;
use App\Payment\Culqi\CulqiClient;
use App\Payment\Culqi\CulqiConfig;
use App\Payment\Culqi\CulqiNotSentException;
use App\Payment\Culqi\CulqiResult;
use App\Payment\Culqi\MalformedCulqiWebhookException;
use App\Services\CulqiPaymentReconciliationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Culqi API V2 — cargos con token de tarjeta o de Yape (POST /v2/charges).
 *
 * Invariantes (probadas en tests/Feature/CulqiPaymentTest.php):
 *  - Importe, moneda y email salen SIEMPRE de la RechargeRequest en el
 *    servidor; el instrumento solo aporta el token (source_id), que Culqi
 *    entrega ya tokenizado — ningún PAN/CVV toca este backend ni sus logs.
 *  - Un PaymentOrder por intento, creado ANTES de llamar a Culqi, con un
 *    `idempotency_key` propio que viaja en metadata del cargo. Culqi no
 *    documenta cabecera de idempotencia, así que la protección contra doble
 *    cobro es: (1) transición atómica prepared→submitting (una sola petición
 *    gana), (2) el POST NUNCA se reintenta, (3) un resultado incierto
 *    (timeout/5xx) deja el intento en 'uncertain' y bloquea nuevos intentos
 *    hasta que la reconciliación lo resuelva consultando la API, y (4) los
 *    tokens de Culqi son de un solo uso.
 *  - Crear el cargo NO acredita nada por sí mismo: el resultado pasa por
 *    CulqiPaymentReconciliationService (contexto + importe + modo) y de ahí
 *    por RechargeApprovalService::credit() (exactamente-una-vez).
 */
class CulqiPaymentProvider implements PaymentProviderContract
{
    public const PROVIDER = 'culqi';

    public function __construct(
        private readonly ?CulqiClient $client = null,
        private readonly ?CulqiPaymentReconciliationService $reconciler = null,
    ) {
    }

    public function createPaymentAttempt(RechargeRequest $recharge, ?TokenizedPaymentInstrument $instrument = null): PaymentOrder
    {
        CulqiConfig::assertConsistent();

        if ($instrument === null) {
            throw new RuntimeException('CulqiPaymentProvider: falta un token de Culqi (Checkout/Culqi.js o Yape); POST /v2/charges no puede armarse sin source_id.');
        }

        if (! $instrument instanceof CardPaymentInstrument && ! $instrument instanceof YapePaymentInstrument) {
            throw new RuntimeException('CulqiPaymentProvider: medio de pago no soportado (solo token de tarjeta o de Yape).');
        }

        $token = $instrument->token;
        if (! preg_match('/^[a-z]{3}_(test|live)_[A-Za-z0-9]+$/', $token) || ! str_contains($token, '_'.CulqiConfig::declaredEnv().'_')) {
            throw new RuntimeException('CulqiPaymentProvider: el token de pago no tiene el formato de Culqi o es de otro entorno (test/live).');
        }

        $email = $recharge->teacherProfile->user->email ?? null;
        if (! is_string($email) || $email === '') {
            throw new RuntimeException('CulqiPaymentProvider: la recarga no tiene un correo de pagador verificable.');
        }

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
            ->update(['submission_status' => 'submitting']);

        if ($claimed !== 1) {
            throw new RuntimeException('CulqiPaymentProvider: otro proceso ya está enviando este intento — no se duplica el cobro.');
        }

        $payload = [
            'amount' => $order->amount_minor,
            'currency_code' => $order->currency,
            'email' => $email,
            'source_id' => $token,
            'capture' => true,
            'description' => 'MOVA recarga de creditos',
            'metadata' => [
                'payment_order_id' => (string) $order->id,
                'recharge_request_id' => (string) $recharge->id,
                'attempt' => (string) $order->attempt_number,
                'idempotency_key' => (string) $order->idempotency_key,
            ],
        ];

        try {
            $result = $client->createCharge($payload);
        } catch (CulqiNotSentException $e) {
            // Se sabe con certeza que no salió nada hacia Culqi: el intento vuelve a 'prepared'.
            PaymentOrder::whereKey($order->id)->where('submission_status', 'submitting')->update(['submission_status' => 'prepared']);

            throw new RuntimeException('CulqiPaymentProvider: la petición no pudo enviarse (configuración de cifrado inválida). No se cobró nada.', previous: $e);
        }

        return $this->handleCreateResult($order, $result);
    }

    public function verifyWebhook(string $rawPayload, array $headers): ?PaymentWebhookEvent
    {
        $expected = config('payments.culqi.webhook_secret');
        $given = $headers['x-mova-webhook-token'] ?? null;

        // Culqi no firma sus notificaciones: el único "secreto" es el valor que MOVA
        // incluye en la URL registrada en CulqiPanel. Comparación en tiempo constante.
        // Un secreto corto (< MIN_WEBHOOK_SECRET_LENGTH) nunca autentica: sería adivinable por fuerza bruta.
        if (! is_string($expected) || strlen($expected) < CulqiConfig::MIN_WEBHOOK_SECRET_LENGTH
            || ! is_string($given) || ! hash_equals($expected, $given)) {
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
            str_starts_with($type, 'charge.') => 'pending',
            default => 'pending',
        };

        // Se persiste solo lo necesario para auditar y reconciliar (ids y tipo): el cuerpo completo de
        // un cargo trae datos del pagador (correo, BIN, antifraude) que no hace falta retener.
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
            providerOrderId: $this->chargeIdFrom($type, $data, $objectId),
            status: $status,
            amountMinor: isset($data['amount']) && is_numeric($data['amount']) ? (int) $data['amount'] : null,
            currency: is_string($data['currency_code'] ?? null) ? $data['currency_code'] : null,
            rawPayload: $minimal,
        );
    }

    // ── Interno ───────────────────────────────────────────────────────────

    private function handleCreateResult(PaymentOrder $order, CulqiResult $result): PaymentOrder
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

        // 200 con action_code (3DS requerido): no es un cargo; este intento no avanza.
        if ($result->status === 200 && isset($result->body['action_code'])) {
            return $this->failAttempt($order, $result, 'requires_3ds');
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

    /** Un evento de devolución trae el id del reembolso; el cargo viene en `charge_id`. */
    private function chargeIdFrom(string $type, array $data, ?string $objectId): ?string
    {
        if (str_starts_with($type, 'refund.')) {
            return is_string($data['charge_id'] ?? null) ? $data['charge_id'] : null;
        }

        return str_starts_with($type, 'charge.') ? $objectId : null;
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
