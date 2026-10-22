<?php

namespace Tests\Concerns;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Doble de la API de Culqi para pruebas de MOVA. Reproduce la forma REAL observada en el sandbox
 * (2026-10-22), no la de la documentación OpenAPI:
 *   - venta exitosa  → HTTP 201, `paid:false`, outcome.type="venta_exitosa", code="AUT0000";
 *   - rechazo        → HTTP 400 con objeto `error` {type:"operacion_denegada", code, charge_id};
 *   - 3DS requerido  → HTTP 200 con `action_code`;
 *   - orden creada   → HTTP 201, state="created", payment_code=null.
 * Toda llamada no prevista responde un 500 visible. Cuenta los POST (incluidos los que terminan en
 * ConnectionException, que Http::recorded no registra) para probar que ninguna escritura se repite.
 */
trait FakesCulqi
{
    /** @var array<string, int> */
    protected array $culqiCalls = ['POST /v2/charges' => 0, 'POST /v2/orders' => 0, 'GET charge' => 0, 'GET order' => 0, 'GET list' => 0];

    /** @var list<array<string, mixed>> cuerpos enviados a POST /v2/charges, en orden */
    protected array $chargePayloads = [];

    /** @var list<array<string, mixed>> cuerpos enviados a POST /v2/orders, en orden */
    protected array $orderPayloads = [];

    protected function fakeCulqi(
        ?callable $postCharge = null,
        ?callable $getCharge = null,
        ?callable $listCharges = null,
        ?callable $postOrder = null,
        ?callable $getOrder = null,
        ?callable $listOrders = null,
    ): void {
        // Http::fake() acumula stubs y gana el primero: se parte de una fábrica limpia en cada llamada.
        Http::swap(new HttpFactory());

        Http::fake(function (Request $r) use ($postCharge, $getCharge, $listCharges, $postOrder, $getOrder, $listOrders) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            $method = $r->method();

            if ($method === 'POST' && $path === '/v2/charges') {
                $this->culqiCalls['POST /v2/charges']++;
                $this->chargePayloads[] = $r->data();

                return $postCharge ? $postCharge($r) : $this->unexpected();
            }
            if ($method === 'POST' && $path === '/v2/orders') {
                $this->culqiCalls['POST /v2/orders']++;
                $this->orderPayloads[] = $r->data();

                return $postOrder ? $postOrder($r) : $this->unexpected();
            }
            if ($method === 'GET' && str_starts_with($path, '/v2/charges/')) {
                $this->culqiCalls['GET charge']++;

                return $getCharge ? $getCharge($r) : $this->unexpected();
            }
            if ($method === 'GET' && str_starts_with($path, '/v2/orders/')) {
                $this->culqiCalls['GET order']++;

                return $getOrder ? $getOrder($r) : $this->unexpected();
            }
            if ($method === 'GET' && $path === '/v2/charges') {
                $this->culqiCalls['GET list']++;

                return $listCharges ? $listCharges($r) : $this->unexpected();
            }
            if ($method === 'GET' && $path === '/v2/orders') {
                $this->culqiCalls['GET list']++;

                return $listOrders ? $listOrders($r) : $this->unexpected();
            }

            return $this->unexpected();
        });
    }

    protected function unexpected()
    {
        return Http::response(['object' => 'error', 'type' => 'api_error', 'merchant_message' => 'llamada no prevista en el test'], 500);
    }

    /** @param array<string, mixed> $payload */
    protected function chargeFor(array $payload, array $override = []): array
    {
        return array_replace([
            'object' => 'charge',
            'id' => 'chr_test_'.Str::random(16),
            'creation_date' => now()->getTimestampMs(),
            'amount' => $payload['amount'] ?? 0,
            'amount_refunded' => 0,
            'current_amount' => $payload['amount'] ?? 0,
            'installments' => 0,
            'currency_code' => $payload['currency_code'] ?? 'PEN',
            'email' => $payload['email'] ?? 'a@b.pe',
            'capture' => true,
            'paid' => false,
            'dispute' => false,
            'duplicated' => false,
            'outcome' => ['type' => 'venta_exitosa', 'code' => 'AUT0000', 'merchant_message' => 'La operación de venta ha sido autorizada exitosamente', 'user_message' => 'Su compra ha sido exitosa'],
            'metadata' => $payload['metadata'] ?? [],
        ], $override);
    }

    protected function declinedError(string $chargeId): array
    {
        return [
            'object' => 'error',
            'type' => 'operacion_denegada',
            'charge_id' => $chargeId,
            'code' => 'DNGE0015',
            'decline_code' => 'insufficient_funds',
            'merchant_message' => 'Fondos insuficientes.',
            'user_message' => 'Su tarjeta no tiene fondos suficientes.',
        ];
    }

    /** Respuesta 200 de Culqi cuando el motor antifraude pide autenticar con 3DS (no se crea cargo). */
    protected function threeDsRequired(): array
    {
        return ['user_message' => 'Se requiere autenticación 3D Secure', 'action_code' => 'REVIEW'];
    }

    /** @return array<string, string> parámetros 3DS con la forma documentada por Culqi */
    protected function parameters3DS(): array
    {
        return [
            'eci' => '05',
            'xid' => '02010000755f8c81a4db4c848b8c23f6b0196cfd',
            'cavv' => '63617264696e616c636f6d6d6572636561757468',
            'protocolVersion' => '2.1.0',
            'directoryServerTransactionId' => '755f8c81-a4db-4c84-8b8c-23f6b0196cfd',
        ];
    }

    /** Forma REAL de una orden recién creada en el sandbox (state=created, sin CIP). */
    protected function orderFor(array $payload, array $override = []): array
    {
        return array_replace([
            'object' => 'order',
            'id' => 'ord_test_'.Str::random(16),
            'amount' => $payload['amount'] ?? 0,
            'payment_code' => null,
            'currency_code' => $payload['currency_code'] ?? 'PEN',
            'description' => $payload['description'] ?? 'MOVA',
            'order_number' => $payload['order_number'] ?? 'mova-test',
            'state' => 'created',
            'total_fee' => null,
            'net_amount' => null,
            'creation_date' => time(),
            'expiration_date' => $payload['expiration_date'] ?? (time() + 3600),
            'updated_at' => time(),
            'paid_at' => null,
            'metadata' => $payload['metadata'] ?? [],
            'qr' => null,
            'url_pe' => null,
        ], $override);
    }

    /** Evento de webhook con `data` como string JSON (esquema NotifyEvent de Culqi). */
    protected function culqiEvent(string $id, string $type, string $objectId, array $data = []): array
    {
        return [
            'object' => 'event',
            'id' => $id,
            'type' => $type,
            'creation_date' => time(),
            'data' => json_encode($data ?: ['id' => $objectId, 'object' => explode('.', $type)[0]]),
        ];
    }
}
