<?php

namespace Tests\Feature;

use App\Models\WhatsAppMessage;
use App\WhatsApp\Waha\WahaSessionState;
use App\WhatsApp\WahaProvider;
use App\WhatsApp\WhatsAppMessageStatus;
use App\WhatsApp\WhatsAppSkipReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * WahaProvider: el proveedor NO lanza nunca, distingue «aceptado» de «entregado», no reintenta a ciegas, deduplica y
 * respeta la lista del piloto, el ritmo y las restricciones de WhatsApp. WAHA se simula con Http::fake (A: mocks).
 */
class WahaProviderTest extends TestCase
{
    use RefreshDatabase;

    private const TO = '987654321';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        RateLimiter::clear('waha:global');
        config([
            'services.waha.require_user_initiated' => false, // la política se prueba en WahaOutreachGuardTest
            'services.waha.warmup_minutes' => 0,
            'services.waha.url' => 'http://127.0.0.1:3000',
            'services.waha.api_key' => 'test-api-key',
            'services.waha.session' => 'default',
            'services.waha.allowed_recipients' => null,
            'services.waha.rate_per_minute' => 100,
            'services.waha.rate_wait_seconds' => 0,
            'services.waha.max_per_recipient_per_day' => 12,
            'services.waha.typing_min_ms' => 0,
            'services.waha.typing_max_ms' => 0,
        ]);
        WahaSessionState::record('WORKING');
    }

    /** Http::fake() acumula stubs y gana el primero: cada simulación parte de una fábrica limpia. */
    private function fake(array|callable $responses): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake($responses);
    }

    private function ok(string $id = 'true_51987654321@c.us_3EB0AAAA1111'): void
    {
        $this->fake(['*' => Http::response(['id' => $id], 201)]);
    }

    public function test_accepted_by_waha_is_recorded_as_sent_never_as_delivered(): void
    {
        $this->ok();

        $this->assertTrue((new WahaProvider())->sendTemplate(self::TO, 'generic_notification', ['Hola profe'], 'Test#1'));

        $row = WhatsAppMessage::firstOrFail();
        $this->assertSame(WhatsAppMessageStatus::Sent, $row->status);
        $this->assertNull($row->delivered_at);
        $this->assertSame('waha', $row->provider);
        $this->assertSame('3EB0AAAA1111', $row->provider_message_id); // id normalizado: coincide con el de message.ack
        $this->assertSame('51987654321', $row->to);
    }

    public function test_request_goes_to_the_session_with_the_api_key_and_the_peruvian_chat_id(): void
    {
        $this->ok();

        (new WahaProvider())->sendTemplate(self::TO, 'generic_notification', ['Hola'], 'Test#1');

        Http::assertSent(function (Request $r) {
            return $r->url() === 'http://127.0.0.1:3000/api/sendText'
                && $r->hasHeader('X-Api-Key', 'test-api-key')
                && $r['chatId'] === '51987654321@c.us'
                && $r['session'] === 'default'
                && str_contains($r['text'], 'Hola')
                && $r['linkPreview'] === false;
        });
    }

    public function test_the_same_message_to_the_same_person_is_sent_once(): void
    {
        $this->ok();
        $provider = new WahaProvider();

        $this->assertTrue($provider->sendTemplate(self::TO, 'generic_notification', ['Clase mañana 10:00'], 'A#1'));
        $this->assertFalse($provider->sendTemplate(self::TO, 'generic_notification', ['Clase mañana 10:00'], 'A#1'));

        Http::assertSentCount(1);
        $this->assertSame(1, WhatsAppMessage::count());
    }

    public function test_a_different_message_is_not_deduplicated(): void
    {
        $this->ok();
        $provider = new WahaProvider();

        $this->assertTrue($provider->sendTemplate(self::TO, 'generic_notification', ['Mensaje uno'], 'A#1'));
        $this->fake(['*' => Http::response(['id' => 'true_x_3EB0BBBB2222'], 201)]);
        $this->assertTrue($provider->sendTemplate(self::TO, 'generic_notification', ['Mensaje dos'], 'A#2'));

        $this->assertSame(2, WhatsAppMessage::count());
    }

    public function test_pilot_allowlist_blocks_everyone_else(): void
    {
        $this->ok();
        config(['services.waha.allowed_recipients' => '+51 987 654 321, 51911111111']);
        $provider = new WahaProvider();

        $this->assertTrue($provider->sendTemplate('987654321', 'generic_notification', ['A'], 'T#1'));
        $this->assertFalse($provider->sendTemplate('955555555', 'generic_notification', ['B'], 'T#2'));

        Http::assertSentCount(1);
        $blocked = WhatsAppMessage::where('to', '51955555555')->firstOrFail();
        $this->assertSame(WhatsAppMessageStatus::Skipped, $blocked->status);
        $this->assertSame(WhatsAppSkipReason::NotAllowlisted, $blocked->skip_reason);
    }

    public function test_daily_cap_per_recipient_skips_the_excess(): void
    {
        $this->ok();
        config(['services.waha.max_per_recipient_per_day' => 2]);
        $provider = new WahaProvider();

        $this->assertTrue($provider->sendTemplate(self::TO, 'generic_notification', ['uno'], 'T#1'));
        $this->fake(['*' => Http::response(['id' => 'true_x_3EB0CCCC3333'], 201)]);
        $this->assertTrue($provider->sendTemplate(self::TO, 'generic_notification', ['dos'], 'T#2'));
        $this->assertFalse($provider->sendTemplate(self::TO, 'generic_notification', ['tres'], 'T#3'));

        $this->assertSame(WhatsAppSkipReason::RateLimited, WhatsAppMessage::latest('id')->first()->skip_reason);
    }

    public function test_global_per_minute_limit_discards_instead_of_blasting(): void
    {
        $this->ok();
        config(['services.waha.rate_per_minute' => 1, 'services.waha.rate_wait_seconds' => 0]);
        $provider = new WahaProvider();

        $this->assertTrue($provider->sendTemplate(self::TO, 'generic_notification', ['uno'], 'T#1'));
        $this->assertFalse($provider->sendTemplate('911111111', 'generic_notification', ['dos'], 'T#2'));

        Http::assertSentCount(1);
        $this->assertSame(WhatsAppSkipReason::RateLimited, WhatsAppMessage::where('to', '51911111111')->firstOrFail()->skip_reason);
    }

    public function test_http_error_is_recorded_as_failed_and_does_not_block_a_later_retry(): void
    {
        $this->fake(['*' => Http::response(['message' => 'bad'], 422)]);
        $provider = new WahaProvider();

        $this->assertFalse($provider->sendTemplate(self::TO, 'generic_notification', ['Hola'], 'T#1'));
        $this->assertSame(WhatsAppMessageStatus::Failed, WhatsAppMessage::firstOrFail()->status);

        $this->ok();
        $this->assertTrue($provider->sendTemplate(self::TO, 'generic_notification', ['Hola'], 'T#1'), 'un fallo definitivo libera la llave de dedupe');
    }

    public function test_a_5xx_is_uncertain_because_waha_may_have_sent_before_failing(): void
    {
        $this->fake(['*' => Http::response(['message' => 'boom'], 500)]);
        $provider = new WahaProvider();

        $this->assertFalse($provider->sendTemplate(self::TO, 'generic_notification', ['Hola'], 'T#1'));
        $this->assertSame(WhatsAppMessageStatus::Unknown, WhatsAppMessage::firstOrFail()->status);

        $this->ok();
        $this->assertFalse($provider->sendTemplate(self::TO, 'generic_notification', ['Hola'], 'T#1'), 'no se reenvía a ciegas');
    }

    public function test_a_failure_after_sending_never_escapes_the_provider(): void
    {
        $this->ok();
        WhatsAppMessage::updating(fn () => throw new \Error('db caída tras enviar'));

        $this->assertFalse((new WahaProvider())->sendTemplate(self::TO, 'generic_notification', ['Hola'], 'T#1'));
        Http::assertSentCount(1);
    }

    public function test_timeout_is_uncertain_not_retried_and_not_duplicated(): void
    {
        $this->fake(fn () => throw new ConnectionException('timeout'));
        $provider = new WahaProvider();

        $this->assertFalse($provider->sendTemplate(self::TO, 'generic_notification', ['Hola'], 'T#1'));
        $this->assertSame(WhatsAppMessageStatus::Unknown, WhatsAppMessage::firstOrFail()->status);

        $this->ok();
        $this->assertFalse($provider->sendTemplate(self::TO, 'generic_notification', ['Hola'], 'T#1'), 'incierto: podría haber salido, no se reenvía a ciegas');
        Http::assertNothingSent();
    }

    public function test_2xx_without_message_id_is_uncertain(): void
    {
        $this->fake(['*' => Http::response(['ok' => true], 201)]);

        $this->assertFalse((new WahaProvider())->sendTemplate(self::TO, 'generic_notification', ['Hola'], 'T#1'));
        $this->assertSame(WhatsAppMessageStatus::Unknown, WhatsAppMessage::firstOrFail()->status);
    }

    public function test_it_never_throws_even_if_waha_is_down(): void
    {
        $this->fake(fn () => throw new \RuntimeException('network down'));

        $this->assertFalse((new WahaProvider())->sendTemplate(self::TO, 'generic_notification', ['Hola'], 'T#1'));
    }

    public function test_nothing_is_sent_while_the_session_is_not_working(): void
    {
        WahaSessionState::record('FAILED');
        $this->fake([]);

        $this->assertFalse((new WahaProvider())->sendTemplate(self::TO, 'generic_notification', ['Hola'], 'T#1'));

        Http::assertNothingSent();
        $row = WhatsAppMessage::firstOrFail();
        $this->assertSame(WhatsAppMessageStatus::Failed, $row->status);
        $this->assertStringContainsString('no disponible', $row->error);
    }

    public function test_unknown_session_state_is_checked_live_and_read_only(): void
    {
        Cache::flush();
        $this->fake([
            '*/api/sessions/default' => Http::response(['name' => 'default', 'status' => 'WORKING'], 200),
            '*/api/sendText' => Http::response(['id' => 'true_x_3EB0DDDD4444'], 201),
        ]);

        $this->assertTrue((new WahaProvider())->sendTemplate(self::TO, 'generic_notification', ['Hola'], 'T#1'));

        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_ends_with($r->url(), '/api/sessions/default'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/logout') || str_contains($r->url(), '/restart'));
    }

    public function test_sending_pauses_while_whatsapp_restricts_new_contacts(): void
    {
        WahaSessionState::pauseUntil(now()->addHours(3));
        $this->fake([]);

        $this->assertFalse((new WahaProvider())->sendTemplate(self::TO, 'generic_notification', ['Hola'], 'T#1'));

        Http::assertNothingSent();
        $this->assertStringContainsString('restringe', WhatsAppMessage::firstOrFail()->error);
    }

    public function test_unsupported_template_and_invalid_numbers_are_recorded_as_failed(): void
    {
        $this->fake([]);
        $provider = new WahaProvider();

        $this->assertFalse($provider->sendTemplate(self::TO, 'plantilla_inexistente', ['x']));
        $this->assertFalse($provider->sendTemplate('123', 'generic_notification', ['x']));

        Http::assertNothingSent();
        $this->assertSame(2, WhatsAppMessage::where('status', WhatsAppMessageStatus::Failed)->count());
    }

    public function test_otp_text_carries_the_code_and_is_exempt_from_dedupe_and_daily_cap(): void
    {
        config(['services.waha.max_per_recipient_per_day' => 1]);
        $this->fake(['*' => Http::response(['id' => 'true_x_3EB0EEEE5555'], 201)]);
        $provider = new WahaProvider();

        $this->assertTrue($provider->sendTemplate(self::TO, 'phone_verification_code', ['123456'], 'phone_verification:7'));
        $this->fake(['*' => Http::response(['id' => 'true_x_3EB0EEEE6666'], 201)]);
        $this->assertTrue($provider->sendTemplate(self::TO, 'phone_verification_code', ['123456'], 'phone_verification:7'));

        Http::assertSent(fn (Request $r) => str_contains($r['text'], '123456') && str_contains($r['text'], 'No lo compartas'));
    }

    public function test_the_api_key_never_appears_in_stored_errors(): void
    {
        $this->fake(fn () => throw new ConnectionException('cURL error ... X-Api-Key: test-api-key'));

        (new WahaProvider())->sendTemplate(self::TO, 'generic_notification', ['Hola'], 'T#1');

        $this->assertStringNotContainsString('test-api-key', (string) WhatsAppMessage::firstOrFail()->error);
    }
}
