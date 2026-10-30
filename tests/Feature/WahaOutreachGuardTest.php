<?php

namespace Tests\Feature;

use App\Models\WhatsAppMessage;
use App\WhatsApp\Waha\WahaAdminPanel;
use App\WhatsApp\Waha\WahaSessionState;
use App\WhatsApp\WahaProvider;
use App\WhatsApp\WhatsAppMessageStatus;
use App\WhatsApp\WhatsAppSkipReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Protección de la vinculación: WhatsApp revocó la sesión 2 s después del primer mensaje a un contacto nuevo
 * (restricción «reachout timelock» + «conflict»). MOVA solo inicia chats con quien escribió primero y no inicia nada
 * durante el calentamiento posterior a una vinculación nueva.
 */
class WahaOutreachGuardTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'hmac-test-key';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        RateLimiter::clear('waha:global');
        config([
            'services.waha.url' => 'http://127.0.0.1:3000',
            'services.waha.api_key' => 'k',
            'services.waha.hmac_key' => self::KEY,
            'services.waha.allowed_recipients' => null,
            'services.waha.rate_wait_seconds' => 0,
            'services.waha.typing_min_ms' => 0,
            'services.waha.typing_max_ms' => 0,
            'services.waha.require_user_initiated' => true,
            'services.waha.warmup_minutes' => 1440,
        ]);
        WahaSessionState::record('WORKING');
        Http::swap(new Factory());
        Http::fake(['*' => Http::response(['id' => 'true_51987654321@c.us_3EB0GUARD0001'], 201)]);
    }

    private function hook(array $body)
    {
        $raw = json_encode($body);

        return $this->call('POST', '/api/webhooks/waha', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WEBHOOK_HMAC' => hash_hmac('sha512', $raw, self::KEY), 'HTTP_X_WEBHOOK_HMAC_ALGORITHM' => 'sha512',
        ], $raw);
    }

    private function inbound(string $from, array $extra = []): void
    {
        $this->hook(['event' => 'message', 'session' => 'default', 'payload' => array_replace_recursive(['id' => 'false_x_ABC', 'from' => $from, 'fromMe' => false, 'body' => 'Hola'], $extra)])->assertOk();
    }

    private function send(string $to = '987654321', string $text = 'Aviso'): bool
    {
        return (new WahaProvider())->sendTemplate($to, 'generic_notification', [$text], 'T#'.$text);
    }

    public function test_nobody_is_contacted_first_by_default(): void
    {
        $this->assertFalse($this->send());

        Http::assertNothingSent();
        $row = WhatsAppMessage::firstOrFail();
        $this->assertSame(WhatsAppMessageStatus::Skipped, $row->status);
        $this->assertSame(WhatsAppSkipReason::NotInitiated, $row->skip_reason);
    }

    public function test_an_inbound_message_opens_the_chat_and_only_that_number(): void
    {
        $this->inbound('51987654321@c.us');

        $this->assertTrue($this->send('987654321'));
        $this->assertFalse($this->send('911111111', 'Otro'));
        $this->assertSame(1, Http::recorded()->count() - Http::recorded(fn ($r) => str_contains($r->url(), 'Typing'))->count());
    }

    public function test_inbound_parsing_ignores_own_messages_groups_and_stores_no_content(): void
    {
        $this->hook(['event' => 'message', 'payload' => ['from' => '51987654321@c.us', 'fromMe' => true, 'body' => 'x']])->assertOk();
        $this->inbound('120363000000000000@g.us');
        $this->assertSame(0, WahaSessionState::contactedCount());

        // Chat @lid: se usa el número alterno que informa el motor.
        $this->inbound('106172447227993@lid', ['_data' => ['key' => ['remoteJidAlt' => '51987654321@s.whatsapp.net']]]);
        $this->assertTrue(WahaSessionState::hasContacted('51987654321'));
        $this->assertStringNotContainsString('51987654321', json_encode(\Illuminate\Support\Facades\DB::table('waha_contacts')->get()), 'solo se guarda un HMAC del número');
        $this->assertStringNotContainsString('Hola', json_encode(\Illuminate\Support\Facades\DB::table('waha_contacts')->get()));
    }

    public function test_a_new_link_starts_a_warm_up_during_which_no_new_chat_is_started(): void
    {
        config(['services.waha.require_user_initiated' => false]);
        WahaSessionState::record('SCAN_QR_CODE');
        WahaSessionState::record('WORKING'); // vinculación nueva

        $this->assertGreaterThan(0, WahaSessionState::warmupRemainingMinutes());
        $this->assertFalse($this->send());
        $this->assertSame(WhatsAppSkipReason::WarmingUp, WhatsAppMessage::firstOrFail()->skip_reason);

        // Quien escribió primero sí recibe respuesta, incluso en calentamiento.
        $this->inbound('51987654321@c.us');
        $this->assertTrue($this->send());

        $this->travel(25)->hours();
        WahaSessionState::record('WORKING');
        $this->assertSame(0, WahaSessionState::warmupRemainingMinutes());
    }

    public function test_a_plain_reconnection_does_not_restart_the_warm_up(): void
    {
        WahaSessionState::record('SCAN_QR_CODE');
        WahaSessionState::record('WORKING');
        $this->travel(2)->days();
        WahaSessionState::record('STARTING'); // reinicio de Docker/VM
        WahaSessionState::record('WORKING');

        $this->assertSame(0, WahaSessionState::warmupRemainingMinutes());
    }

    public function test_replies_to_people_who_wrote_first_are_allowed_during_a_reachout_restriction(): void
    {
        config(['services.waha.require_user_initiated' => false, 'services.waha.warmup_minutes' => 0]);
        WahaSessionState::pauseUntil(now()->addHours(6));

        $this->assertFalse($this->send()); // chat nuevo: pausado por la restricción
        $this->inbound('51987654321@c.us');
        $this->assertTrue($this->send('987654321', 'Respuesta'));
    }

    public function test_losing_the_link_raises_an_alert_that_clears_when_it_reconnects(): void
    {
        $this->assertNull(WahaSessionState::linkLost());

        WahaSessionState::record('FAILED');
        $this->assertSame('FAILED', WahaSessionState::linkLost()['status']);

        WahaSessionState::record('WORKING');
        $this->assertNull(WahaSessionState::linkLost());
    }

    public function test_the_backup_notice_stores_only_a_fingerprint(): void
    {
        $this->hook(['event' => 'mova.backup', 'payload' => ['sha256' => str_repeat('a1', 32), 'bytes' => 4096, 'ts' => now()->getTimestamp()]])->assertOk();
        $this->hook(['event' => 'mova.backup', 'payload' => ['sha256' => 'no-es-un-hash', 'bytes' => 1, 'ts' => now()->getTimestamp()]])->assertOk();

        $backup = WahaSessionState::lastBackup();
        $this->assertSame('a1a1a1a1a1a1a1a1', $backup['sha']);
        $this->assertSame(4096, $backup['bytes']);
    }

    // ── Endurecimiento tras la revisión de seguridad ─────────────────────────────────────────────────

    public function test_group_and_status_authors_never_count_as_having_written_first(): void
    {
        $this->inbound('120363000000000000@g.us', ['participant' => '51987654321@c.us', '_data' => ['key' => ['participantPn' => '51987654321@s.whatsapp.net']]]);
        $this->inbound('status@broadcast', ['_data' => ['key' => ['participantPn' => '51911111111@s.whatsapp.net', 'senderPn' => '51922222222@s.whatsapp.net']]]);
        $this->inbound('51933333333@c.us', ['participant' => '51944444444@c.us']);
        $this->inbound('120363111111111111@newsletter');

        $this->assertSame(0, WahaSessionState::contactedCount());
        $this->assertFalse($this->send('987654321'));
    }

    public function test_the_policy_state_survives_a_cache_flush_like_the_one_done_at_every_container_start(): void
    {
        $this->inbound('51987654321@c.us');
        WahaSessionState::pauseUntil(now()->addHours(3));
        WahaSessionState::record('SCAN_QR_CODE');
        WahaSessionState::record('WORKING');
        WahaSessionState::recordBackup(str_repeat('b2', 32), 100);

        Cache::flush(); // docker/entrypoint.sh: optimize:clear

        $this->assertTrue(WahaSessionState::hasContacted('51987654321'));
        $this->assertNotNull(WahaSessionState::pausedUntil());
        $this->assertGreaterThan(0, WahaSessionState::warmupRemainingMinutes());
        $this->assertNotNull(WahaSessionState::lastWorkingAt());
        $this->assertSame('b2b2b2b2b2b2b2b2', WahaSessionState::lastBackup()['sha']);
    }

    public function test_marking_the_same_contact_twice_is_idempotent(): void
    {
        $this->inbound('51987654321@c.us');
        $this->inbound('51987654321@c.us');
        $this->inbound('51911111111@c.us');

        $this->assertSame(2, WahaSessionState::contactedCount());
    }

    public function test_a_replayed_backup_notice_with_an_old_timestamp_is_ignored(): void
    {
        $this->hook(['event' => 'mova.backup', 'payload' => ['sha256' => str_repeat('c3', 32), 'bytes' => 10, 'ts' => now()->subHour()->getTimestamp()]])->assertOk();
        $this->hook(['event' => 'mova.backup', 'payload' => ['sha256' => str_repeat('c3', 32), 'bytes' => 10]])->assertOk();

        $this->assertNull(WahaSessionState::lastBackup());
    }

    public function test_going_straight_from_working_to_a_qr_request_raises_the_lost_link_alert_unless_an_admin_just_acted(): void
    {
        WahaSessionState::record('WORKING');
        WahaSessionState::record('SCAN_QR_CODE');
        $this->assertSame('SCAN_QR_CODE', WahaSessionState::linkLost()['status']);

        // Una acción del administrador (restablecer) hace esperado pasar por STOPPED/SCAN_QR_CODE.
        \Illuminate\Support\Facades\DB::table('waha_state')->truncate();
        WahaSessionState::record('WORKING');
        WahaSessionState::noteAdminAction();
        WahaSessionState::record('STOPPED');
        $this->assertNull(WahaSessionState::linkLost());
    }

    public function test_missing_state_tables_never_break_the_webhook_or_the_provider_and_sending_stays_closed(): void
    {
        \Illuminate\Support\Facades\Schema::drop('waha_state');
        \Illuminate\Support\Facades\Schema::drop('waha_contacts');
        Cache::flush();

        // Despliegue de la imagen ANTES de `migrate`: nada lanza y nadie recibe un primer mensaje.
        $this->inbound('51987654321@c.us');
        $this->hook(['event' => 'session.status', 'payload' => ['status' => 'WORKING']])->assertOk();
        $this->assertFalse(WahaSessionState::hasContacted('51987654321'));
        $this->assertFalse($this->send());
        $this->assertNull(WahaSessionState::lastBackup());
    }

    public function test_the_migration_can_be_run_twice_and_rolled_back(): void
    {
        $migration = require base_path('database/migrations/2026_10_30_100000_create_waha_state_tables.php');

        $migration->up(); // ya existen: no debe fallar
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('waha_state'));

        $migration->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('waha_contacts'));
        $migration->up();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('waha_contacts'));
    }

    public function test_rotating_app_key_does_not_orphan_contacts_when_a_dedicated_hash_key_is_set(): void
    {
        config(['services.waha.contact_hash_key' => 'clave-propia-del-hash']);
        $this->inbound('51987654321@c.us');

        config(['app.key' => 'base64:'.base64_encode(str_repeat('z', 32))]);

        $this->assertTrue(WahaSessionState::hasContacted('51987654321'));
    }

    public function test_the_admin_test_waits_for_the_recipient_to_write_first(): void
    {
        config([
            'services.waha.admin_control_enabled' => true, 'services.waha.expected_number' => '905922835',
            'services.waha.admin_test_recipient' => '916666934', 'services.waha.allowed_recipients' => '51916666934',
        ]);
        Http::swap(new Factory());
        Http::fake(['*/api/sessions/default' => Http::response(['status' => 'WORKING', 'me' => ['id' => '51905922835@c.us']], 200), '*' => Http::response(['id' => 'true_x_3EB0'], 201)]);

        $blocked = (new WahaAdminPanel())->snapshot();
        $this->assertFalse($blocked['test']['canSend']);
        $this->assertStringContainsString('escribe un «Hola»', $blocked['test']['blockedReason']);

        $this->inbound('51916666934@c.us');
        Cache::forget('waha:admin:session');
        $this->assertTrue((new WahaAdminPanel())->snapshot()['test']['canSend']);
    }
}
