<?php

namespace Tests\Feature;

use App\Payment\MercadoPago\MercadoPagoWebhookSignatureVerifier;
use Tests\TestCase;

/**
 * Cubre MercadoPagoWebhookSignatureVerifier de forma aislada (lógica pura,
 * sin HTTP/DB) contra fixtures de HMAC-SHA256 calculados de antemano con
 * `php -r` sobre el mismo secreto/manifest que el propio verificador arma
 * — así un bug real en cómo se construye el manifest (orden de campos,
 * falta un ';', etc.) sí hace fallar el test, en vez de que el test
 * recalcule con el mismo código bajo prueba y nunca pueda detectarlo.
 *
 * secret='test_secret', ts='1700000000000', data.id='order123' (o su
 * variante en mayúsculas 'ORDER123' — debe normalizar igual),
 * request-id='req-123'.
 */
class MercadoPagoWebhookSignatureVerifierTest extends TestCase
{
    private const SECRET = 'test_secret';

    private const TS = '1700000000000';

    // hash_hmac('sha256', 'id:order123;request-id:req-123;ts:1700000000000;', 'test_secret')
    private const VALID_HASH = '7c43b5180966298420cad5f8a31cbd3c60ef21da0e11d5a22e0b32dd50fa3ff8';

    // Manifest sin el segmento "id:...;" (data.id no vino presente)
    private const HASH_WITHOUT_DATA_ID = '6ba8485a6f8fe4a653359c434a610bf05ffe1430bc2dffdbb228c6a7e589d88c';

    // Manifest sin el segmento "request-id:...;" (x-request-id no vino presente)
    private const HASH_WITHOUT_REQUEST_ID = 'a4fb4cf11a0fe32d12df3d4e19320baa2d2177f25b52bbb67472a1c1adb97778';

    public function test_valid_signature_passes(): void
    {
        $verifier = new MercadoPagoWebhookSignatureVerifier();

        $ok = $verifier->verify(
            headers: [
                'x-signature' => 'ts='.self::TS.',v1='.self::VALID_HASH,
                'x-request-id' => 'req-123',
            ],
            dataId: 'order123',
            secret: self::SECRET,
        );

        $this->assertTrue($ok);
    }

    public function test_data_id_is_lowercased_before_hashing(): void
    {
        // Mercado Pago documenta que data.id se normaliza a minúsculas
        // antes de armar el manifest si viene en mayúsculas.
        $verifier = new MercadoPagoWebhookSignatureVerifier();

        $ok = $verifier->verify(
            headers: [
                'x-signature' => 'ts='.self::TS.',v1='.self::VALID_HASH,
                'x-request-id' => 'req-123',
            ],
            dataId: 'ORDER123',
            secret: self::SECRET,
        );

        $this->assertTrue($ok);
    }

    public function test_missing_x_signature_header_fails(): void
    {
        $verifier = new MercadoPagoWebhookSignatureVerifier();

        $ok = $verifier->verify(
            headers: ['x-request-id' => 'req-123'],
            dataId: 'order123',
            secret: self::SECRET,
        );

        $this->assertFalse($ok);
    }

    public function test_empty_x_signature_header_fails(): void
    {
        $verifier = new MercadoPagoWebhookSignatureVerifier();

        $ok = $verifier->verify(
            headers: ['x-signature' => '', 'x-request-id' => 'req-123'],
            dataId: 'order123',
            secret: self::SECRET,
        );

        $this->assertFalse($ok);
    }

    /** @dataProvider malformedSignatureHeaders */
    public function test_malformed_signature_header_fails(string $header): void
    {
        $verifier = new MercadoPagoWebhookSignatureVerifier();

        $ok = $verifier->verify(
            headers: ['x-signature' => $header, 'x-request-id' => 'req-123'],
            dataId: 'order123',
            secret: self::SECRET,
        );

        $this->assertFalse($ok);
    }

    public static function malformedSignatureHeaders(): array
    {
        return [
            'sin formato clave=valor' => ['not-a-valid-header'],
            'solo ts, falta v1' => ['ts='.self::TS],
            'solo v1, falta ts' => ['v1='.self::VALID_HASH],
            'ts vacío' => ['ts=,v1='.self::VALID_HASH],
            'v1 vacío' => ['ts='.self::TS.',v1='],
            'cadena vacía' => [''],
        ];
    }

    public function test_wrong_hash_fails(): void
    {
        $verifier = new MercadoPagoWebhookSignatureVerifier();

        $ok = $verifier->verify(
            headers: [
                'x-signature' => 'ts='.self::TS.',v1=0000000000000000000000000000000000000000000000000000000000000000',
                'x-request-id' => 'req-123',
            ],
            dataId: 'order123',
            secret: self::SECRET,
        );

        $this->assertFalse($ok);
    }

    public function test_wrong_request_id_fails(): void
    {
        // La firma se calculó con request-id=req-123, pero el header real
        // trae otro valor — el hash ya no coincide.
        $verifier = new MercadoPagoWebhookSignatureVerifier();

        $ok = $verifier->verify(
            headers: [
                'x-signature' => 'ts='.self::TS.',v1='.self::VALID_HASH,
                'x-request-id' => 'req-OTRO',
            ],
            dataId: 'order123',
            secret: self::SECRET,
        );

        $this->assertFalse($ok);
    }

    public function test_wrong_data_id_fails(): void
    {
        $verifier = new MercadoPagoWebhookSignatureVerifier();

        $ok = $verifier->verify(
            headers: [
                'x-signature' => 'ts='.self::TS.',v1='.self::VALID_HASH,
                'x-request-id' => 'req-123',
            ],
            dataId: 'order999',
            secret: self::SECRET,
        );

        $this->assertFalse($ok);
    }

    public function test_missing_secret_fails(): void
    {
        $verifier = new MercadoPagoWebhookSignatureVerifier();

        $ok = $verifier->verify(
            headers: [
                'x-signature' => 'ts='.self::TS.',v1='.self::VALID_HASH,
                'x-request-id' => 'req-123',
            ],
            dataId: 'order123',
            secret: null,
        );

        $this->assertFalse($ok);
    }

    public function test_empty_secret_fails(): void
    {
        $verifier = new MercadoPagoWebhookSignatureVerifier();

        $ok = $verifier->verify(
            headers: [
                'x-signature' => 'ts='.self::TS.',v1='.self::VALID_HASH,
                'x-request-id' => 'req-123',
            ],
            dataId: 'order123',
            secret: '',
        );

        $this->assertFalse($ok);
    }

    public function test_manifest_omits_data_id_segment_when_not_present(): void
    {
        // Si data.id no vino presente, el manifest NO debe rellenarlo con
        // cadena vacía — el segmento se omite por completo (especificación
        // oficial). Este hash solo es válido si el verificador realmente
        // omite el segmento en vez de escribir "id:;".
        $verifier = new MercadoPagoWebhookSignatureVerifier();

        $ok = $verifier->verify(
            headers: [
                'x-signature' => 'ts='.self::TS.',v1='.self::HASH_WITHOUT_DATA_ID,
                'x-request-id' => 'req-123',
            ],
            dataId: null,
            secret: self::SECRET,
        );

        $this->assertTrue($ok);
    }

    public function test_manifest_omits_request_id_segment_when_not_present(): void
    {
        $verifier = new MercadoPagoWebhookSignatureVerifier();

        $ok = $verifier->verify(
            headers: [
                'x-signature' => 'ts='.self::TS.',v1='.self::HASH_WITHOUT_REQUEST_ID,
                // x-request-id deliberadamente ausente
            ],
            dataId: 'order123',
            secret: self::SECRET,
        );

        $this->assertTrue($ok);
    }

    public function test_comparison_is_case_sensitive_on_the_hash_itself(): void
    {
        // hash_equals() es constant-time pero SÍ distingue mayúsculas de
        // minúsculas en el hash — a diferencia de data.id, el hash en sí
        // no se normaliza. Un v1 en mayúsculas (aunque representara los
        // mismos bytes) debe fallar tal como se recibe.
        $verifier = new MercadoPagoWebhookSignatureVerifier();

        $ok = $verifier->verify(
            headers: [
                'x-signature' => 'ts='.self::TS.',v1='.strtoupper(self::VALID_HASH),
                'x-request-id' => 'req-123',
            ],
            dataId: 'order123',
            secret: self::SECRET,
        );

        $this->assertFalse($ok);
    }
}
