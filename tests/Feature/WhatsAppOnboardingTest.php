<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Notifications\WhatsAppTestNotification;
use App\WhatsApp\Waha\WahaSessionState;
use App\WhatsApp\WhatsAppConsent;
use App\WhatsApp\WhatsAppMessageStatus;
use App\WhatsApp\WhatsAppSkipReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * WhatsApp desde el registro, sin comandos («ACTIVAR»/«Hola»): consentimiento explícito con versión y finalidad, verificación
 * real del número, estado calculado en el servidor, baja por WhatsApp y límites de seguridad de WAHA.
 */
class WhatsAppOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['parent', 'teacher', 'admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Cache::flush();
        RateLimiter::clear('waha:global');
    }

    private function register(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        Notification::fake();

        return $this->post('/register', array_merge([
            'name' => 'Profe Prueba',
            'email' => 'profe@example.com',
            'role' => 'parent',
            'accepted_terms' => true,
            'password' => 'password',
            'password_confirmation' => 'password',
            'phone' => '916666934',
        ], $overrides));
    }

    private function wahaOn(array $extra = []): void
    {
        config(array_merge([
            'services.whatsapp.enabled' => true,
            'services.whatsapp.provider' => 'waha',
            'services.waha.url' => 'http://127.0.0.1:3000',
            'services.waha.api_key' => 'k',
            'services.waha.hmac_key' => 'h',
            'services.waha.typing_min_ms' => 0,
            'services.waha.typing_max_ms' => 0,
            'services.waha.rate_wait_seconds' => 0,
            'services.waha.warmup_minutes' => 0,
            'services.waha.allowed_recipients' => '',
        ], $extra));
        WahaSessionState::record('WORKING');
        $n = 0;
        Http::fake(function () use (&$n) {
            $n++;

            return Http::response(['id' => 'true_51900000000@c.us_3EB0ONB'.$n], 201);
        });
    }

    private function verifiedConsenting(string $phone = '916666934', array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'phone' => $phone,
            'phone_verified_at' => now(),
            'phone_verified_normalized' => '+51'.$phone,
            'whatsapp_opt_in_at' => now(),
        ], $attrs));
    }

    // ── Registro ─────────────────────────────────────────────────────────────────────────────────

    public function test_registration_with_consent_records_it_with_version_and_sends_to_phone_verification(): void
    {
        $response = $this->register(['whatsapp_consent' => true]);

        $user = User::where('email', 'profe@example.com')->firstOrFail();
        $this->assertNotNull($user->whatsapp_opt_in_at);
        $this->assertNull($user->phone_verified_at, 'El consentimiento no verifica el número.');
        $this->assertDatabaseHas('whatsapp_consent_events', ['user_id' => $user->id, 'action' => 'opt_in', 'source' => 'registration', 'text_version' => WhatsAppConsent::VERSION, 'purpose' => WhatsAppConsent::PURPOSE]);
        $response->assertRedirect(route('phone.verification.notice'));
        $this->assertSame(WhatsAppConsent::NEEDS_VERIFICATION, WhatsAppConsent::status($user));
    }

    public function test_teacher_registration_with_consent_returns_to_the_teacher_setup_after_verifying(): void
    {
        $this->register(['role' => 'teacher', 'whatsapp_consent' => true, 'teacher_subject_names' => ['Matemáticas']]);

        $this->assertSame(route('teacher.setup'), session('url.intended'));
    }

    public function test_registration_without_consent_does_not_opt_in(): void
    {
        $response = $this->register();

        $user = User::where('email', 'profe@example.com')->firstOrFail();
        $this->assertNull($user->whatsapp_opt_in_at);
        $this->assertDatabaseCount('whatsapp_consent_events', 0);
        $response->assertRedirect(route('students.create'));
    }

    public function test_consent_without_a_phone_is_ignored(): void
    {
        $this->register(['whatsapp_consent' => true, 'phone' => null]);

        $this->assertNull(User::where('email', 'profe@example.com')->firstOrFail()->whatsapp_opt_in_at);
    }

    // ── Verificación del número ──────────────────────────────────────────────────────────────────

    public function test_verifying_the_phone_after_registration_consent_activates_the_channel_without_asking_again(): void
    {
        $user = User::factory()->create(['phone' => '916666934', 'whatsapp_opt_in_at' => now()]);
        $user->forceFill(['phone_verification_code_hash' => Hash::make('123456'), 'phone_verification_expires_at' => now()->addMinutes(10), 'phone_verification_attempts' => 0])->save();

        $this->actingAs($user)->post(route('phone.verification.verify'), ['code' => '123456']);

        $user->refresh();
        $this->assertNotNull($user->phone_verified_at);
        $this->assertSame(WhatsAppConsent::ACTIVE, WhatsAppConsent::status($user));
        $this->assertDatabaseMissing('whatsapp_consent_events', ['source' => 'phone_verification']); // no se registra un segundo consentimiento
    }

    public function test_a_wrong_code_never_verifies_the_number(): void
    {
        $user = User::factory()->create(['phone' => '916666934', 'whatsapp_opt_in_at' => now()]);
        $user->forceFill(['phone_verification_code_hash' => Hash::make('123456'), 'phone_verification_expires_at' => now()->addMinutes(10), 'phone_verification_attempts' => 0])->save();

        $this->actingAs($user)->post(route('phone.verification.verify'), ['code' => '000000']);

        $this->assertNull($user->fresh()->phone_verified_at);
        $this->assertSame(WhatsAppConsent::NEEDS_VERIFICATION, WhatsAppConsent::status($user->fresh()));
    }

    // ── Perfil: estado real y acciones ───────────────────────────────────────────────────────────

    public function test_the_shared_status_comes_from_the_server_for_each_segment(): void
    {
        $cases = [
            WhatsAppConsent::ACTIVE => $this->verifiedConsenting('911111111'),
            WhatsAppConsent::NEEDS_VERIFICATION => User::factory()->create(['phone' => '922222222', 'whatsapp_opt_in_at' => now()]),
            WhatsAppConsent::NOT_AUTHORIZED => User::factory()->create(['phone' => '933333333', 'phone_verified_at' => now(), 'phone_verified_normalized' => '+51933333333']),
            WhatsAppConsent::REVOKED => $this->verifiedConsenting('944444444', ['whatsapp_opt_out_at' => now()]),
        ];

        foreach ($cases as $expected => $user) {
            $this->actingAs($user)->get(route('profile.edit'))
                ->assertInertia(fn ($page) => $page->where('auth.user.whatsapp_status', $expected));
        }
    }

    public function test_opting_out_from_the_profile_is_audited_and_keeps_the_original_consent_date(): void
    {
        $user = $this->verifiedConsenting();
        $original = $user->whatsapp_opt_in_at->toDateTimeString();

        $this->actingAs($user)->patch(route('profile.notifications.update'), ['whatsapp' => false]);

        $user->refresh();
        $this->assertSame(WhatsAppConsent::REVOKED, WhatsAppConsent::status($user));
        $this->assertSame($original, $user->whatsapp_opt_in_at->toDateTimeString());
        $this->assertDatabaseHas('whatsapp_consent_events', ['user_id' => $user->id, 'action' => 'opt_out', 'source' => 'profile']);
    }

    public function test_an_existing_user_without_consent_can_enable_it_with_one_action(): void
    {
        $user = User::factory()->create(['phone' => '916666934', 'phone_verified_at' => now(), 'phone_verified_normalized' => '+51916666934']);

        $this->actingAs($user)->patch(route('profile.notifications.update'), ['whatsapp' => true]);

        $this->assertSame(WhatsAppConsent::ACTIVE, WhatsAppConsent::status($user->fresh()));
        $this->assertDatabaseHas('whatsapp_consent_events', ['user_id' => $user->id, 'action' => 'opt_in', 'source' => 'profile', 'text_version' => WhatsAppConsent::VERSION]);
    }

    public function test_the_profile_no_longer_asks_anyone_to_write_activar(): void
    {
        $this->actingAs($this->verifiedConsenting())->get(route('profile.edit'))
            ->assertInertia(fn ($page) => $page->missing('whatsappChat'));
    }

    // ── Baja por WhatsApp ────────────────────────────────────────────────────────────────────────

    public function test_writing_baja_on_whatsapp_opts_out_the_verified_owner_of_that_number(): void
    {
        config(['services.waha.hmac_key' => 'h']);
        $owner = $this->verifiedConsenting('916666934');
        $other = $this->verifiedConsenting('987654321');

        $body = json_encode(['event' => 'message', 'session' => 'default', 'payload' => ['from' => '51916666934@c.us', 'fromMe' => false, 'body' => ' Baja ']]);
        $ts = (string) (time() * 1000);
        $this->call('POST', '/api/webhooks/waha', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_HMAC' => hash_hmac('sha512', $body, 'h'),
            'HTTP_X_WEBHOOK_HMAC_ALGORITHM' => 'sha512',
            'HTTP_X_WEBHOOK_TIMESTAMP' => $ts,
        ], $body)->assertSuccessful();

        $this->assertSame(WhatsAppConsent::REVOKED, WhatsAppConsent::status($owner->fresh()));
        $this->assertSame(WhatsAppConsent::ACTIVE, WhatsAppConsent::status($other->fresh()));
        $this->assertDatabaseHas('whatsapp_consent_events', ['user_id' => $owner->id, 'action' => 'opt_out', 'source' => 'whatsapp_keyword']);
    }

    // ── WAHA: sin «ACTIVAR»/«Hola», con límites ──────────────────────────────────────────────────

    public function test_a_consenting_verified_user_receives_messages_without_writing_first(): void
    {
        $this->wahaOn();
        $user = $this->verifiedConsenting();

        $user->notify(new WhatsAppTestNotification('Aviso de prueba de MOVA'));

        $this->assertSame(1, WhatsAppMessage::where('status', WhatsAppMessageStatus::Sent)->count());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/sendText'));
    }

    public function test_the_strict_policy_can_still_be_restored_by_configuration(): void
    {
        $this->wahaOn(['services.waha.require_user_initiated' => true]);

        $this->verifiedConsenting()->notify(new WhatsAppTestNotification('Aviso de prueba de MOVA'));

        $this->assertDatabaseHas('whatsapp_messages', ['status' => WhatsAppMessageStatus::Skipped->value, 'skip_reason' => WhatsAppSkipReason::NotInitiated->value]);
    }

    public function test_the_warm_up_after_a_new_link_still_protects_the_number(): void
    {
        $this->wahaOn(['services.waha.warmup_minutes' => 1440]);
        WahaSessionState::record('SCAN_QR_CODE');
        WahaSessionState::record('WORKING'); // vinculación nueva ahora mismo

        $this->verifiedConsenting()->notify(new WhatsAppTestNotification('Aviso de prueba de MOVA'));

        $this->assertDatabaseHas('whatsapp_messages', ['skip_reason' => WhatsAppSkipReason::WarmingUp->value]);
    }

    public function test_first_contacts_are_capped_per_day_but_known_recipients_are_not(): void
    {
        $this->wahaOn(['services.waha.max_new_contacts_per_day' => 1, 'services.waha.rate_per_minute' => 100]);
        $first = $this->verifiedConsenting('911111111');
        $second = $this->verifiedConsenting('922222222');

        $first->notify(new WhatsAppTestNotification('Aviso de prueba de MOVA'));
        WhatsAppMessage::where('to', '51911111111')->update(['status' => WhatsAppMessageStatus::Delivered]); // ACK: chat real
        $second->notify(new WhatsAppTestNotification('Aviso de prueba de MOVA'));
        $first->notify(new WhatsAppTestNotification('Otro aviso'));

        $this->assertSame(2, WhatsAppMessage::where('to', '51911111111')->whereIn('status', [WhatsAppMessageStatus::Sent, WhatsAppMessageStatus::Delivered])->count());
        $this->assertDatabaseHas('whatsapp_messages', ['to' => '51922222222', 'skip_reason' => WhatsAppSkipReason::RateLimited->value]);
    }

    public function test_no_consent_means_no_message_even_with_a_verified_phone(): void
    {
        $this->wahaOn();
        $user = User::factory()->create(['phone' => '916666934', 'phone_verified_at' => now(), 'phone_verified_normalized' => '+51916666934']);

        $user->notify(new WhatsAppTestNotification('Aviso de prueba de MOVA'));

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/api/sendText'));
    }

    public function test_otp_codes_to_new_numbers_have_their_own_daily_cap_and_do_not_open_a_chat(): void
    {
        $this->wahaOn(['services.waha.max_new_otp_per_day' => 1, 'services.waha.max_new_contacts_per_day' => 0, 'services.waha.rate_per_minute' => 100]);
        $provider = app(\App\WhatsApp\Contracts\WhatsAppProviderContract::class);

        $this->assertTrue($provider->sendTemplate('+51911111111', 'phone_verification_code', ['123456'], 'otp#1'));
        $this->assertFalse($provider->sendTemplate('+51922222222', 'phone_verification_code', ['654321'], 'otp#2'), 'Cupo de OTP a números nuevos agotado.');

        // Haber recibido un OTP no convierte el número en «conocido»: un aviso sigue contando como primer contacto (cupo 0).
        $this->verifiedConsenting('911111111')->notify(new WhatsAppTestNotification('Aviso de prueba de MOVA'));
        $this->assertDatabaseHas('whatsapp_messages', ['to' => '51911111111', 'template_key' => 'generic_notification', 'skip_reason' => WhatsAppSkipReason::RateLimited->value]);
    }

    public function test_changing_the_phone_resets_consent_and_leaves_an_audit_event(): void
    {
        $user = $this->verifiedConsenting();

        $this->actingAs($user)->patch(route('profile.update'), ['name' => $user->name, 'email' => $user->email, 'phone' => '987650000']);

        $this->assertNull($user->fresh()->whatsapp_opt_in_at);
        $this->assertDatabaseHas('whatsapp_consent_events', ['user_id' => $user->id, 'action' => 'opt_in_reset', 'source' => 'phone_changed']);
    }
}
