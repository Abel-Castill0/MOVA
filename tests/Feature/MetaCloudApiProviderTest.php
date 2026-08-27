<?php

namespace Tests\Feature;

use App\Models\WhatsAppMessage;
use App\WhatsApp\MetaCloudApiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Prueba MetaCloudApiProvider contra la forma real del payload de la Graph
 * API de Meta (mensajes de plantilla), sin tocar red real — Http::fake()
 * intercepta la llamada. No requiere credenciales reales.
 *
 * Cubre explícitamente los modos de fallo de una API real que una PR de
 * revisión señaló como faltantes: 400, 401, 429, 500, timeout/excepción de
 * red, JSON malformado — y que en TODOS los casos MOVA nunca reintenta a
 * ciegas (ver el comentario en MetaCloudApiProvider sobre por qué no hay
 * retry automático) ni deja un registro de auditoría inconsistente.
 */
class MetaCloudApiProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta_whatsapp.phone_number_id' => '123456',
            'services.meta_whatsapp.access_token' => 'test-token',
            'services.meta_whatsapp.api_version' => 'v20.0',
            'services.meta_whatsapp.templates.generic_notification' => [
                'name' => 'mova_generic_notification',
                'language' => 'es_PE',
            ],
            'services.meta_whatsapp.templates.phone_verification_code' => [
                'name' => 'mova_otp',
                'language' => 'es_PE',
                'button' => ['enabled' => true, 'sub_type' => 'url'],
            ],
        ]);
    }

    public function test_sends_the_exact_graph_api_template_payload(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test']]], 200)]);

        $sent = (new MetaCloudApiProvider())->sendTemplate('+51987654321', 'generic_notification', ['Hola, tu clase fue confirmada.']);

        $this->assertTrue($sent);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://graph.facebook.com/v20.0/123456/messages'
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && $request['messaging_product'] === 'whatsapp'
                && $request['to'] === '51987654321'
                && $request['type'] === 'template'
                && $request['template']['name'] === 'mova_generic_notification'
                && $request['template']['language']['code'] === 'es_PE'
                && $request['template']['components'][0]['type'] === 'body'
                && $request['template']['components'][0]['parameters'][0] === ['type' => 'text', 'text' => 'Hola, tu clase fue confirmada.'];
        });
    }

    public function test_records_a_sent_row_with_the_provider_message_id(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.abc123']]], 200)]);

        (new MetaCloudApiProvider())->sendTemplate('+51987654321', 'generic_notification', ['x']);

        $this->assertDatabaseHas('whatsapp_messages', [
            'to' => '+51987654321',
            'template_key' => 'generic_notification',
            'provider' => 'meta',
            'provider_message_id' => 'wamid.abc123',
            'status' => 'sent',
        ]);
    }

    public function test_otp_template_sends_a_button_component_with_the_same_code(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.otp']]], 200)]);

        (new MetaCloudApiProvider())->sendTemplate('+51987654321', 'phone_verification_code', ['654321']);

        Http::assertSent(function ($request) {
            $components = $request['template']['components'];

            return $components[0]['type'] === 'body'
                && $components[0]['parameters'][0]['text'] === '654321'
                && $components[1]['type'] === 'button'
                && $components[1]['sub_type'] === 'url'
                && $components[1]['index'] === 0
                && $components[1]['parameters'][0]['text'] === '654321';
        });
    }

    public function test_generic_template_never_includes_a_button_component(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200)]);

        (new MetaCloudApiProvider())->sendTemplate('+51987654321', 'generic_notification', ['x']);

        Http::assertSent(fn ($request) => count($request['template']['components']) === 1);
    }

    public function test_returns_false_without_crediting_when_credentials_missing(): void
    {
        config(['services.meta_whatsapp.access_token' => null]);
        Http::fake();

        $sent = (new MetaCloudApiProvider())->sendTemplate('+51987654321', 'generic_notification', ['x']);

        $this->assertFalse($sent);
        Http::assertNothingSent();
        $this->assertDatabaseCount('whatsapp_messages', 0);
    }

    public function test_returns_false_when_template_not_configured(): void
    {
        config(['services.meta_whatsapp.templates.phone_verification_code' => ['name' => null]]);
        Http::fake();

        $sent = (new MetaCloudApiProvider())->sendTemplate('+51987654321', 'phone_verification_code', ['123456']);

        $this->assertFalse($sent);
        Http::assertNothingSent();
    }

    /** @dataProvider metaHttpErrorStatuses */
    public function test_returns_false_and_logs_failure_on_meta_error_statuses(int $status): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'x', 'code' => $status]], $status)]);

        $sent = (new MetaCloudApiProvider())->sendTemplate('+51987654321', 'generic_notification', ['x']);

        $this->assertFalse($sent);
        $this->assertDatabaseHas('whatsapp_messages', ['status' => 'failed', 'provider_message_id' => null]);
    }

    public static function metaHttpErrorStatuses(): array
    {
        return [
            'bad request' => [400],
            'unauthorized (token inválido/expirado)' => [401],
            'rate limited' => [429],
            'meta caído' => [500],
            'gateway' => [503],
        ];
    }

    public function test_returns_false_on_malformed_json_response(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response('<html>not json</html>', 400)]);

        $sent = (new MetaCloudApiProvider())->sendTemplate('+51987654321', 'generic_notification', ['x']);

        $this->assertFalse($sent);
        $this->assertDatabaseHas('whatsapp_messages', ['status' => 'failed']);
    }

    public function test_returns_false_without_retry_on_connection_timeout(): void
    {
        Http::fake(['graph.facebook.com/*' => function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        }]);

        $sent = (new MetaCloudApiProvider())->sendTemplate('+51987654321', 'generic_notification', ['x']);

        $this->assertFalse($sent);
        // MetaCloudApiProvider no tiene ->retry() en la cadena de Http — un
        // solo intento posible por diseño, nunca reintenta a ciegas tras un
        // timeout. El estado es 'unknown', no 'failed': un timeout no
        // confirma que Meta rechazó el mensaje, solo que MOVA no obtuvo
        // respuesta (ver WhatsAppMessageStatus).
        $this->assertDatabaseHas('whatsapp_messages', ['status' => 'unknown']);
    }

    public function test_otp_code_never_ends_up_in_the_audit_log_error_field(): void
    {
        // Meta puede rechazar la plantilla OTP (p. ej. si todavía no está
        // aprobada) — el error que MOVA registra debe seguir sin contener
        // el código de un solo uso en texto plano en ningún campo de
        // auditoría, ni siquiera al fallar.
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Template rejected']], 400)]);

        (new MetaCloudApiProvider())->sendTemplate('+51987654321', 'phone_verification_code', ['999888']);

        $message = WhatsAppMessage::first();
        $this->assertStringNotContainsString('999888', (string) $message->error);
        $this->assertSame('failed', $message->status->value);
    }

    public function test_returns_false_when_response_has_no_message_id_but_is_successful(): void
    {
        // F-23: el nombre de este test ya decía "returns_false" desde antes
        // de la corrección — la aserción de abajo (assertTrue + status=sent)
        // contradecía su propio nombre. Un 200 sin el array "messages"
        // esperado NO es 'sent': sin wamid, MOVA no puede correlacionar
        // ningún webhook futuro con esta fila — es un resultado incierto
        // ('unknown'), no una confirmación de entrega.
        Http::fake(['graph.facebook.com/*' => Http::response([], 200)]);

        $sent = (new MetaCloudApiProvider())->sendTemplate('+51987654321', 'generic_notification', ['x']);

        $this->assertFalse($sent);
        $this->assertDatabaseHas('whatsapp_messages', ['status' => 'unknown', 'provider_message_id' => null]);
    }

    public function test_a_2xx_response_with_a_message_id_is_recorded_as_sent(): void
    {
        // Caso positivo explícito, para que quede claro que la corrección de
        // arriba no afecta el camino normal: con wamid presente, sigue
        // siendo 'sent' y sendTemplate() sigue devolviendo true.
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ok']]], 200)]);

        $sent = (new MetaCloudApiProvider())->sendTemplate('+51987654321', 'generic_notification', ['x']);

        $this->assertTrue($sent);
        $this->assertDatabaseHas('whatsapp_messages', ['status' => 'sent', 'provider_message_id' => 'wamid.ok']);
    }
}
