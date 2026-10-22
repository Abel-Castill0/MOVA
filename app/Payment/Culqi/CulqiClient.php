<?php

namespace App\Payment\Culqi;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Cliente HTTP mínimo de la API V2 de Culqi (https://apidocs.culqi.com/).
 *
 * Reglas (todas verificadas en tests/Feature/CulqiPaymentTest.php):
 *  - Las ESCRITURAS (POST) jamás se reintentan: Culqi no documenta una cabecera
 *    de idempotencia, así que repetir un POST tras un timeout podría duplicar
 *    un cargo. Un resultado incierto lo resuelve la reconciliación (GET).
 *  - Las LECTURAS (GET) reintentan solo ante fallos de conexión.
 *  - La llave secreta viaja únicamente en la cabecera Authorization; nunca se
 *    registra, ni en logs ni en CulqiResult.
 *  - Todos los métodos devuelven CulqiResult (no lanzan por HTTP 4xx/5xx).
 */
class CulqiClient
{
    public function __construct(
        private readonly ?string $secretKey = null,
        private readonly ?string $publicKey = null,
        private readonly ?CulqiPayloadEncryptor $encryptor = null,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            CulqiConfig::secretKey(),
            CulqiConfig::publicKey(),
            CulqiPayloadEncryptor::fromConfig(),
        );
    }

    // ── Cargos ────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $payload */
    public function createCharge(array $payload): CulqiResult
    {
        return $this->post($this->baseUrl().'/v2/charges', $payload, $this->secret());
    }

    public function getCharge(string $id): CulqiResult
    {
        return $this->get($this->baseUrl().'/v2/charges/'.rawurlencode($id), [], $this->secret());
    }

    /** @param array<string, scalar> $query */
    public function listCharges(array $query = []): CulqiResult
    {
        return $this->get($this->baseUrl().'/v2/charges', $this->limited($query), $this->secret());
    }

    // ── Devoluciones ──────────────────────────────────────────────────────

    /** @param array{amount:int, charge_id:string, reason:string, metadata?:array} $payload */
    public function createRefund(array $payload): CulqiResult
    {
        return $this->post($this->baseUrl().'/v2/refunds', $payload, $this->secret());
    }

    public function getRefund(string $id): CulqiResult
    {
        return $this->get($this->baseUrl().'/v2/refunds/'.rawurlencode($id), [], $this->secret());
    }

    /** @param array<string, scalar> $query */
    public function listRefunds(array $query = []): CulqiResult
    {
        return $this->get($this->baseUrl().'/v2/refunds', $this->limited($query), $this->secret());
    }

    // ── Órdenes ───────────────────────────────────────────────────────────

    /** @param array<string, mixed> $payload */
    public function createOrder(array $payload): CulqiResult
    {
        return $this->post($this->baseUrl().'/v2/orders', $payload, $this->secret());
    }

    public function getOrder(string $id): CulqiResult
    {
        return $this->get($this->baseUrl().'/v2/orders/'.rawurlencode($id), [], $this->secret());
    }

    /** @param array<string, scalar> $query */
    public function listOrders(array $query = []): CulqiResult
    {
        return $this->get($this->baseUrl().'/v2/orders', $this->limited($query), $this->secret());
    }

    // ── Eventos y conciliación ────────────────────────────────────────────

    public function getEvent(string $id): CulqiResult
    {
        return $this->get($this->baseUrl().'/v2/events/'.rawurlencode($id), [], $this->secret());
    }

    /** @param array<string, scalar> $query */
    public function listEvents(array $query = []): CulqiResult
    {
        return $this->get($this->baseUrl().'/v2/events', $this->limited($query), $this->secret());
    }

    /** Abonos del estado de cuenta; Culqi exige currency_code, date_from y date_to (UNIX, segundos). */
    public function listDeposits(string $currency = 'PEN', ?int $fromUnix = null, ?int $toUnix = null): CulqiResult
    {
        $to = $toUnix ?? time();

        return $this->get($this->baseUrl().'/v2/balance/deposits', [
            'currency_code' => $currency,
            'date_from' => $fromUnix ?? $to - 30 * 86400,
            'date_to' => $to,
        ], $this->secret());
    }

    // ── Tokens (SOLO sandbox: ver SandboxTokenFactory/culqi:sandbox-smoke) ─

    /** @param array<string, mixed> $payload */
    public function createToken(array $payload): CulqiResult
    {
        return $this->post($this->secureUrl().'/v2/tokens', $payload, $this->publicBearer());
    }

    // ── Transporte ────────────────────────────────────────────────────────

    /** @param array<string, mixed> $payload */
    private function post(string $url, array $payload, string $bearer): CulqiResult
    {
        $request = $this->pending($bearer);

        if ($this->encryptor !== null) {
            try {
                $payload = $this->encryptor->encrypt($payload);
            } catch (Throwable $e) {
                // Nada salió hacia Culqi: se distingue de un timeout (resultado incierto).
                throw new CulqiNotSentException('CulqiClient: no se pudo cifrar el payload; la petición no se envió.', previous: $e);
            }
            $request = $request->withHeaders(['x-culqi-rsa-id' => (string) config('payments.culqi.rsa_id')]);
        }

        try {
            // Sin retry(): una escritura nunca se repite automáticamente.
            return $this->wrap($request->asJson()->post($url, $payload));
        } catch (Throwable $e) {
            return $this->transportFailure($e);
        }
    }

    /** @param array<string, scalar> $query */
    private function get(string $url, array $query, string $bearer): CulqiResult
    {
        try {
            $response = $this->pending($bearer)
                ->retry(2, 200, fn (Throwable $e) => $e instanceof ConnectionException, throw: false)
                ->get($url, $query);

            return $this->wrap($response);
        } catch (Throwable $e) {
            return $this->transportFailure($e);
        }
    }

    private function pending(string $bearer): PendingRequest
    {
        return Http::withToken($bearer)
            ->acceptJson()
            ->connectTimeout((int) config('payments.culqi.connect_timeout', 5))
            ->timeout((int) config('payments.culqi.timeout', 15));
    }

    private function wrap(\Illuminate\Http\Client\Response $response): CulqiResult
    {
        $json = $response->json();

        return new CulqiResult(
            status: $response->status(),
            body: is_array($json) ? $json : null,
            trackingId: $response->header('x-culqi-tracking-id') ?: null,
            environment: $response->header('x-culqi-environment') ?: null,
        );
    }

    private function transportFailure(Throwable $e): CulqiResult
    {
        // Solo la clase de la excepción: su mensaje podría incluir la URL con parámetros.
        return new CulqiResult(0, null, null, null, class_basename($e));
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, scalar>
     */
    private function limited(array $query): array
    {
        $query['limit'] = max(1, min((int) ($query['limit'] ?? 10), 100));

        return $query;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('payments.culqi.base_url', 'https://api.culqi.com'), '/');
    }

    private function secureUrl(): string
    {
        return rtrim((string) config('payments.culqi.secure_url', 'https://secure.culqi.com'), '/');
    }

    private function secret(): string
    {
        if ($this->secretKey === null) {
            throw new RuntimeException('CulqiClient: CULQI_SECRET_KEY no está configurada.');
        }

        return $this->secretKey;
    }

    private function publicBearer(): string
    {
        if ($this->publicKey === null) {
            throw new RuntimeException('CulqiClient: CULQI_PUBLIC_KEY no está configurada.');
        }

        return $this->publicKey;
    }
}
