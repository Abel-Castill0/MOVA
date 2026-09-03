<?php

namespace App\Payment\MercadoPago;

/**
 * Verificador dedicado de la firma `x-signature` de Mercado Pago (Orders
 * API), aislado de MercadoPagoPaymentProvider para poder testearlo unit por
 * unit sin pasar por HTTP/DB. Implementa el algoritmo documentado
 * oficialmente (verificado contra la documentación vigente antes de
 * escribir esto, no memoria de entrenamiento):
 *
 *   header:  X-Signature: ts=<timestamp>,v1=<hmac_hex>
 *   header:  X-Request-Id: <request_id>
 *   query:   data.id=<resource_id>  (id del recurso, va en la URL de retorno
 *            del webhook — NO es el id de la notificación)
 *
 *   manifest = "id:{data.id};request-id:{x-request-id};ts:{ts};"
 *   firma esperada = hash_hmac('sha256', manifest, secret)  (hex)
 *
 * Reglas de la especificación oficial que este verificador respeta:
 *   - data.id se compara en minúsculas si el proveedor lo entrega en
 *     mayúsculas (Mercado Pago normaliza así en su propio ejemplo).
 *   - Si data.id o x-request-id no vienen presentes en la notificación
 *     recibida, esa parte se OMITE del manifest (no se rellena con "").
 *   - La comparación es constant-time (hash_equals), nunca ===.
 *   - Sin secreto configurado, sin header, o con el header mal formado:
 *     inválida, siempre — nunca "válida por defecto".
 *
 * Nunca loggea el secreto ni el valor completo de x-signature/v1 — solo un
 * resultado booleano y, cuando ayuda a depurar, qué PARTE faltaba (nunca el
 * valor).
 */
final class MercadoPagoWebhookSignatureVerifier
{
    /**
     * @param  array<string,string>  $headers  claves en minúsculas: 'x-signature', 'x-request-id'
     */
    public function verify(array $headers, ?string $dataId, ?string $secret): bool
    {
        if (! $secret) {
            return false;
        }

        $signatureHeader = $headers['x-signature'] ?? null;
        if (! is_string($signatureHeader) || $signatureHeader === '') {
            return false;
        }

        $parsed = $this->parseSignatureHeader($signatureHeader);
        if ($parsed === null) {
            return false;
        }

        [$ts, $expectedHash] = $parsed;
        if ($expectedHash === '') {
            return false;
        }

        $requestId = $headers['x-request-id'] ?? null;
        $manifest = $this->buildManifest($dataId, $requestId, $ts);

        $computedHash = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($computedHash, $expectedHash);
    }

    /**
     * Parsea "ts=1742505638683,v1=ced36ab6..." → [ts, v1_hash]. Tolera
     * espacios alrededor de la coma (visto en ejemplos de distintos SDKs de
     * terceros) y orden de partes invertido, pero exige que ambas claves
     * 'ts' y 'v1' estén presentes — cualquier otra forma es inválida.
     *
     * @return array{0:string,1:string}|null
     */
    private function parseSignatureHeader(string $header): ?array
    {
        $ts = null;
        $v1 = null;

        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '' || ! str_contains($part, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $part, 2);
            $key = strtolower(trim($key));
            $value = trim($value);

            if ($key === 'ts') {
                $ts = $value;
            } elseif ($key === 'v1') {
                $v1 = $value;
            }
        }

        if ($ts === null || $ts === '' || $v1 === null) {
            return null;
        }

        return [$ts, $v1];
    }

    /**
     * "id:{data.id};request-id:{x-request-id};ts:{ts};" — cada segmento se
     * omite por completo (no como cadena vacía) si el valor de origen no
     * vino presente, tal como documenta Mercado Pago.
     */
    private function buildManifest(?string $dataId, ?string $requestId, string $ts): string
    {
        $manifest = '';

        if ($dataId !== null && $dataId !== '') {
            $manifest .= 'id:'.strtolower($dataId).';';
        }

        if ($requestId !== null && $requestId !== '') {
            $manifest .= 'request-id:'.$requestId.';';
        }

        $manifest .= 'ts:'.$ts.';';

        return $manifest;
    }
}
