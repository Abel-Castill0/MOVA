<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Notifications\WhatsAppTestNotification;
use App\WhatsApp\Waha\WahaAdminPanel;
use App\WhatsApp\Waha\WahaSessionState;
use App\WhatsApp\WhatsAppMessageStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Panel nativo /admin/whatsapp: estado REAL de la sesión, QR PNG válido, control explícito y mensaje de prueba fijo.
 * WAHA se simula con Http::fake; la prueba contra el WAHA real se hace en el piloto.
 */
class WahaAdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'secret-key-xyz';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        RateLimiter::clear('waha-admin-control');
        RateLimiter::clear('waha-admin-pair');
        config([
            'services.whatsapp.enabled' => false,   // los avisos a usuarios siguen APAGADOS
            'services.whatsapp.provider' => 'fake',
            'services.waha.admin_control_enabled' => true,
            'services.waha.url' => 'http://127.0.0.1:3000',
            'services.waha.api_key' => self::KEY,
            'services.waha.session' => 'default',
            'services.waha.expected_number' => '905922835',
            'services.waha.admin_test_recipient' => '916666934',
            'services.waha.allowed_recipients' => '51916666934',
            'services.waha.typing_min_ms' => 0,
            'services.waha.typing_max_ms' => 0,
            'services.waha.rate_wait_seconds' => 0,
        ]);
    }

    private function admin(): User
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function pngBody(): string
    {
        return chr(0x89).'PNG'.str_repeat('a', 300);
    }

    /** Simula WAHA: sesión + endpoints de control. $qr = respuesta de /auth/qr. */
    private function waha(string $status, ?string $me = null, $qr = null, array $post = []): void
    {
        Http::swap(new Factory());
        Http::fake(function (Request $r) use ($status, $me, $qr, $post) {
            $url = $r->url();
            if ($r->method() === 'GET' && str_ends_with($url, '/api/sessions/default')) {
                return Http::response(['name' => 'default', 'status' => $status, 'me' => $me ? ['id' => $me] : null], 200);
            }
            if ($r->method() === 'GET' && str_contains($url, '/auth/qr')) {
                return $qr ?? Http::response($this->pngBody(), 200, ['Content-Type' => 'image/png']);
            }
            if (str_ends_with($url, '/auth/request-code')) {
                return Http::response(['code' => 'AB12-CD34'], 201);
            }
            if (str_ends_with($url, '/api/sendText')) {
                return Http::response(['id' => 'true_51916666934@c.us_3EB0PANEL0001'], 201);
            }

            return Http::response($post[basename($url)] ?? [], 201);
        });
    }

    private function sent(string $suffix): int
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), $suffix))->count();
    }

    // ── Acceso ──────────────────────────────────────────────────────────────────────────────────────

    public function test_an_authorized_admin_opens_the_panel_and_no_secret_reaches_the_browser(): void
    {
        $this->waha('WORKING', '51905922835@c.us');

        $response = $this->actingAs($this->admin())->get(route('admin.whatsapp.index'))->assertOk();

        $response->assertInertia(fn ($page) => $page->component('Admin/WhatsAppStatus')->where('panel.state', 'connected')->where('panel.number.matches', true));
        $this->assertStringNotContainsString(self::KEY, $response->getContent());

        $json = $this->actingAs($this->admin())->getJson(route('admin.whatsapp.status'))->assertOk();
        $this->assertStringNotContainsString(self::KEY, $json->getContent());
        $json->assertHeader('Cache-Control');
        $this->assertStringContainsString('no-store', $json->headers->get('Cache-Control'));
    }

    public function test_parents_teachers_and_guests_cannot_reach_any_whatsapp_route(): void
    {
        $this->waha('WORKING', '51905922835@c.us');
        foreach (['parent', 'teacher'] as $role) {
            Role::findOrCreate($role, 'web');
            $user = User::factory()->create();
            $user->assignRole($role);

            $this->actingAs($user)->get(route('admin.whatsapp.index'))->assertForbidden();
            $this->actingAs($user)->getJson(route('admin.whatsapp.status'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.whatsapp.qr'))->assertForbidden();
            $this->actingAs($user)->postJson(route('admin.whatsapp.control', 'restart'), ['confirm' => true])->assertForbidden();
            $this->actingAs($user)->postJson(route('admin.whatsapp.pairing-code'))->assertForbidden();
            $this->actingAs($user)->postJson(route('admin.whatsapp.send-test'))->assertForbidden();
        }

        auth()->logout();
        $this->get(route('admin.whatsapp.index'))->assertRedirect(route('login'));
        Http::assertNothingSent();
    }

    // ── Estado real ────────────────────────────────────────────────────────────────────────────────

    public function test_status_is_read_only_and_never_restarts_logs_out_or_starts_the_session(): void
    {
        $this->waha('FAILED', '51905922835@c.us');

        $this->actingAs($this->admin())->getJson(route('admin.whatsapp.status'))->assertOk()->assertJsonPath('panel.state', 'error');
        $this->actingAs($this->admin())->get(route('admin.whatsapp.index'))->assertOk();

        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    }

    public function test_connected_requires_the_working_status_not_just_an_http_200_or_a_known_number(): void
    {
        // Tras pedir un código de emparejamiento WAHA ya conoce el número en `me`, sin estar vinculado.
        $this->waha('FAILED', '51905922835@c.us');
        $this->assertSame('error', $this->state());
        $this->assertNull($this->panel()['number']['connected']);

        $this->waha('SCAN_QR_CODE', '51905922835@c.us');
        $this->assertSame('waiting_qr', $this->state());
        $this->waha('STARTING');
        $this->assertSame('starting', $this->state());
        $this->waha('STOPPED');
        $this->assertSame('disconnected', $this->state());
    }

    public function test_the_connected_number_must_be_the_dedicated_one(): void
    {
        $this->waha('WORKING', '51999888777@c.us');

        $panel = $this->panel();
        $this->assertSame('wrong_number', $panel['state']);
        $this->assertFalse($panel['number']['matches']);
        $this->assertFalse($panel['test']['canSend']);
    }

    public function test_an_unreachable_or_unauthorized_waha_is_reported_as_unavailable(): void
    {
        Http::swap(new Factory());
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $panel = $this->panel();
        $this->assertSame('unavailable', $panel['state']);
        $this->assertFalse($panel['server']['reachable']);
        $this->assertStringContainsString('No hay conexión', $panel['error']);

        Cache::flush();
        Http::swap(new Factory());
        Http::fake(['*' => Http::response(['message' => 'Unauthorized'], 401)]);
        $this->assertStringContainsString('rechazó las credenciales', $this->panel()['error']);
    }

    public function test_when_admin_control_is_disabled_the_panel_does_nothing_and_asks_nothing_of_waha(): void
    {
        config(['services.waha.admin_control_enabled' => false]);
        $this->waha('WORKING', '51905922835@c.us');

        $this->assertSame('disabled', $this->state());
        $this->actingAs($this->admin())->get(route('admin.whatsapp.qr'))->assertForbidden();
        $this->actingAs($this->admin())->postJson(route('admin.whatsapp.control', 'restart'), ['confirm' => true])->assertStatus(422);
        Http::assertNothingSent();
    }

    // ── QR ───────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_qr_is_a_real_png_with_no_store_only_while_waiting_for_the_scan(): void
    {
        $this->waha('SCAN_QR_CODE');

        $res = $this->actingAs($this->admin())->get(route('admin.whatsapp.qr'))->assertOk();

        $this->assertSame('image/png', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertStringStartsWith(chr(0x89).'PNG', $res->getContent());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/auth/qr') && $r->hasHeader('X-Api-Key', self::KEY));
    }

    public function test_the_qr_accepts_waha_json_with_base64_and_never_forwards_errors_as_an_image(): void
    {
        $this->waha('SCAN_QR_CODE', null, Http::response(['mimetype' => 'image/png', 'data' => base64_encode($this->pngBody())], 200, ['Content-Type' => 'application/json']));
        $this->actingAs($this->admin())->get(route('admin.whatsapp.qr'))->assertOk()->assertHeader('Content-Type', 'image/png');

        foreach ([Http::response('<html>error</html>', 200, ['Content-Type' => 'text/html']), Http::response(['message' => 'boom'], 200), Http::response('', 500)] as $bad) {
            Cache::flush();
            $this->waha('SCAN_QR_CODE', null, $bad);
            $res = $this->actingAs($this->admin())->get(route('admin.whatsapp.qr'));
            $res->assertStatus(502)->assertJsonStructure(['message']);
            $this->assertStringNotContainsString('image/', (string) $res->headers->get('Content-Type'));
        }
    }

    public function test_no_qr_is_served_when_the_session_is_not_waiting_for_a_scan(): void
    {
        foreach (['WORKING' => '51905922835@c.us', 'FAILED' => null, 'STOPPED' => null] as $status => $me) {
            Cache::flush();
            $this->waha($status, $me);
            $this->actingAs($this->admin())->get(route('admin.whatsapp.qr'))->assertStatus(409)->assertJsonPath('state', $this->state());
        }
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/auth/qr'));
    }

    // ── Control de la sesión ───────────────────────────────────────────────────────────────────────

    public function test_recover_from_failed_requires_explicit_confirmation_and_restarts_once(): void
    {
        $this->waha('FAILED', '51905922835@c.us');
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('admin.whatsapp.control', 'restart'))->assertStatus(422);
        $this->assertSame(0, $this->sent('/restart'));

        $this->actingAs($admin)->postJson(route('admin.whatsapp.control', 'restart'), ['confirm' => true])->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(1, $this->sent('/restart'));
        $this->assertSame(0, $this->sent('/logout'));
    }

    public function test_reset_needs_the_typed_confirmation_and_logs_out_then_starts_clean(): void
    {
        $this->waha('FAILED', '51905922835@c.us');
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('admin.whatsapp.control', 'reset'), ['confirm' => 'si'])->assertStatus(422);
        $this->assertSame(0, $this->sent('/logout'));

        $this->actingAs($admin)->postJson(route('admin.whatsapp.control', 'reset'), ['confirm' => 'RESTABLECER'])->assertOk();
        $this->assertSame(1, $this->sent('/logout'));
        $this->assertSame(1, $this->sent('/start'));
    }

    public function test_a_connected_session_is_never_restarted_or_reset(): void
    {
        $this->waha('WORKING', '51905922835@c.us');
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('admin.whatsapp.control', 'restart'), ['confirm' => true])->assertStatus(422);
        $this->actingAs($admin)->postJson(route('admin.whatsapp.control', 'reset'), ['confirm' => 'RESTABLECER'])->assertStatus(422);

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_control_actions_are_rate_limited_so_there_is_no_restart_loop(): void
    {
        $this->waha('FAILED');
        $admin = $this->admin();

        for ($i = 0; $i < 4; $i++) {
            Cache::forget('waha:admin:session');
            $this->actingAs($admin)->postJson(route('admin.whatsapp.control', 'restart'), ['confirm' => true])->assertOk();
        }
        Cache::forget('waha:admin:session');
        $blocked = $this->actingAs($admin)->postJson(route('admin.whatsapp.control', 'restart'), ['confirm' => true])->assertStatus(422);
        $this->assertStringContainsString('límite', $blocked->json('message'));

        $this->assertSame(4, $this->sent('/restart'));
    }

    public function test_starting_a_stopped_session_is_a_single_explicit_post(): void
    {
        $this->waha('STOPPED');

        $this->actingAs($this->admin())->postJson(route('admin.whatsapp.control', 'start'), ['confirm' => true])->assertOk();

        $this->assertSame(1, $this->sent('/start'));
    }

    // ── Código de emparejamiento ─────────────────────────────────────────────────────────────────────

    public function test_pairing_code_is_on_demand_for_the_dedicated_number_and_never_logged(): void
    {
        $this->waha('SCAN_QR_CODE');
        $logged = [];
        Log::listen(function ($event) use (&$logged) { $logged[] = $event->message.json_encode($event->context); });

        $res = $this->actingAs($this->admin())->postJson(route('admin.whatsapp.pairing-code'))->assertOk()->assertJsonPath('code', 'AB12-CD34');

        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/auth/request-code') && $r['phoneNumber'] === '51905922835');
        foreach ($logged as $line) {
            $this->assertStringNotContainsString('AB12', $line);
        }
    }

    // ── Mensaje de prueba ────────────────────────────────────────────────────────────────────────────

    public function test_the_test_message_goes_only_to_the_fixed_recipient_with_the_fixed_text_even_with_avisos_off(): void
    {
        $this->waha('WORKING', '51905922835@c.us');

        $this->actingAs($this->admin())->postJson(route('admin.whatsapp.send-test'), ['phone' => '999999999', 'message' => 'otro texto'])
            ->assertOk()->assertJsonPath('ok', true);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/sendText')
            && $r['chatId'] === '51916666934@c.us'
            && str_contains($r['text'], WahaAdminPanel::TEST_MESSAGE)
            && ! str_contains($r['text'], 'otro texto'));
        $this->assertSame(1, $this->sent('/api/sendText'));
        $row = WhatsAppMessage::firstOrFail();
        $this->assertSame(WhatsAppMessageStatus::Sent, $row->status);   // aceptado ≠ entregado
        $this->assertNull($row->delivered_at);
        $this->assertStringStartsWith(WahaAdminPanel::TEST_REFERENCE_PREFIX, $row->client_reference);
    }

    public function test_a_second_identical_test_is_not_duplicated(): void
    {
        $this->waha('WORKING', '51905922835@c.us');
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('admin.whatsapp.send-test'))->assertOk();
        Cache::forget('waha:admin:session');
        $this->actingAs($admin)->postJson(route('admin.whatsapp.send-test'))->assertStatus(422);

        $this->assertSame(1, $this->sent('/api/sendText'));
    }

    public function test_the_test_is_blocked_unless_connected_to_the_right_number_and_recipient_is_allowlisted(): void
    {
        $admin = $this->admin();

        $this->waha('SCAN_QR_CODE');
        $this->actingAs($admin)->postJson(route('admin.whatsapp.send-test'))->assertStatus(422);

        Cache::flush();
        config(['services.waha.allowed_recipients' => '51900000000']);
        $this->waha('WORKING', '51905922835@c.us');
        $this->actingAs($admin)->postJson(route('admin.whatsapp.send-test'))->assertStatus(422)->assertSee('lista autorizada');

        $this->assertSame(0, $this->sent('/api/sendText'));
    }

    public function test_the_panel_reports_sent_delivered_and_read_from_the_ack_without_calling_sent_delivered(): void
    {
        $this->waha('WORKING', '51905922835@c.us');
        $admin = $this->admin();
        $this->actingAs($admin)->postJson(route('admin.whatsapp.send-test'))->assertOk();

        $this->assertSame('sent', $this->panel()['test']['last']['status']);
        $this->assertNull($this->panel()['test']['last']['deliveredAt']);

        WhatsAppMessage::firstOrFail()->update(['status' => WhatsAppMessageStatus::Delivered, 'delivered_at' => now(), 'status_updated_at' => now()]);
        Cache::forget('waha:admin:session');
        $last = $this->panel()['test']['last'];
        $this->assertSame('delivered', $last['status']);
        $this->assertNotNull($last['deliveredAt']);
        $this->assertSame('3EB0PANEL0001', $last['messageId']);
    }

    public function test_general_notifications_to_users_stay_off_while_admin_control_is_on(): void
    {
        $this->waha('WORKING', '51905922835@c.us');
        $user = User::factory()->create([
            'phone' => '987654321', 'phone_verified_at' => now(), 'phone_verified_normalized' => '+51987654321', 'whatsapp_opt_in_at' => now(),
        ]);

        $user->notifyNow(new WhatsAppTestNotification('hola'));

        $this->assertSame(0, $this->sent('/api/sendText'));
        $this->assertSame(0, WhatsAppMessage::count());
        $this->assertFalse((bool) config('services.whatsapp.enabled'));
    }

    // ── Endurecimiento tras la revisión de seguridad ─────────────────────────────────────────────────

    public function test_working_without_a_verifiable_number_is_never_connected_and_cannot_be_reset(): void
    {
        $this->waha('WORKING', null);
        $panel = $this->panel();
        $this->assertSame('unverified_number', $panel['state']);
        $this->assertFalse($panel['actions']['reset']);
        $this->assertFalse($panel['test']['canSend']);

        Cache::flush();
        config(['services.waha.expected_number' => null]);
        $this->waha('WORKING', '51905922835@c.us');
        $this->assertSame('unverified_number', $this->panel()['state']);
    }

    public function test_the_device_suffix_in_the_connected_id_is_ignored(): void
    {
        $this->waha('WORKING', '51905922835:12@s.whatsapp.net');

        $this->assertSame('connected', $this->panel()['state']);
    }

    public function test_reset_is_only_offered_for_an_explicit_failed_status_not_for_unknown_ones(): void
    {
        $this->waha('SOMETHING_NEW');
        $panel = $this->panel();
        $this->assertSame('error', $panel['state']);
        $this->assertFalse($panel['actions']['reset']);
        $this->assertTrue($panel['actions']['restart']);

        Cache::flush();
        $this->waha('FAILED');
        $this->assertTrue($this->panel()['actions']['reset']);
    }

    public function test_a_pairing_code_is_refused_by_the_server_unless_the_session_waits_for_linking(): void
    {
        foreach (['WORKING' => '51905922835@c.us', 'FAILED' => null, 'STOPPED' => null] as $status => $me) {
            Cache::flush();
            $this->waha($status, $me);
            $this->actingAs($this->admin())->postJson(route('admin.whatsapp.pairing-code'))->assertStatus(422);
        }

        $this->assertSame(0, $this->sent('/auth/request-code'));
    }

    public function test_the_cached_session_snapshot_never_stores_waha_session_configuration(): void
    {
        Http::swap(new Factory());
        Http::fake(['*' => Http::response(['name' => 'default', 'status' => 'WORKING', 'me' => ['id' => '51905922835@c.us'], 'config' => ['webhooks' => [['hmac' => ['key' => 'TOP-SECRET-HMAC']]]]], 200)]);

        $this->panel();

        $this->assertStringNotContainsString('TOP-SECRET-HMAC', json_encode(Cache::get('waha:admin:session')));
    }

    public function test_waha_redirects_are_not_followed_so_the_api_key_cannot_leak_to_another_host(): void
    {
        Http::swap(new Factory());
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://evil.example/x'])]);

        $this->assertSame('unavailable', $this->panel()['state']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'evil.example'));
    }

    // ── Utilidades ───────────────────────────────────────────────────────────────────────────────────

    private function panel(): array
    {
        return (new WahaAdminPanel())->snapshot();
    }

    private function state(): string
    {
        Cache::forget('waha:admin:session');

        return $this->panel()['state'];
    }
}
