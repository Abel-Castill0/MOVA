<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AdminMfaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Camino REAL de MFA (sin el atajo de TestCase): las acciones sensibles del panel exigen MFA reciente. */
class WahaAdminPanelMfaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $bypassAdminMfa = false;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'services.waha.admin_control_enabled' => true,
            'services.waha.url' => 'http://127.0.0.1:3000',
            'services.waha.api_key' => 'k',
            'services.waha.expected_number' => '905922835',
        ]);
        Http::swap(new Factory());
        Http::fake(['*' => Http::response(['name' => 'default', 'status' => 'SCAN_QR_CODE'], 200)]);
    }

    private function admin(bool $enroll = true): User
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        if ($enroll) {
            $admin->forceFill(['two_factor_secret' => (new Google2FA())->generateSecretKey(32), 'two_factor_confirmed_at' => now()])->save();
        }

        return $admin;
    }

    public function test_an_unenrolled_admin_is_sent_to_mfa_setup(): void
    {
        $this->actingAs($this->admin(false))->get(route('admin.whatsapp.index'))->assertRedirect(route('admin.mfa.setup'));
        Http::assertNothingSent();
    }

    public function test_an_enrolled_admin_without_verification_gets_the_challenge_and_nothing_reaches_waha(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.whatsapp.index'))->assertRedirect(route('admin.mfa.challenge'));
        $this->actingAs($admin)->postJson(route('admin.whatsapp.control', 'restart'), ['confirm' => true])->assertForbidden();
        $this->actingAs($admin)->postJson(route('admin.whatsapp.send-test'))->assertForbidden();
        $this->actingAs($admin)->getJson(route('admin.whatsapp.qr'))->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_a_stale_verification_allows_reading_but_blocks_the_sensitive_actions(): void
    {
        $admin = $this->admin();
        // Verificado hace 60 min: vale para la sesión (720 min) pero NO para el step-up (15 min).
        $this->actingAs($admin)->withSession([AdminMfaService::SESSION_KEY => ['user_id' => $admin->id, 'at' => now()->subMinutes(60)->getTimestamp()]]);

        $this->getJson(route('admin.whatsapp.status'))->assertOk()->assertJsonPath('stepUp.valid', false);
        $this->get(route('admin.whatsapp.qr'), ['Accept' => 'application/json'])->assertForbidden();
        $this->postJson(route('admin.whatsapp.control', 'restart'), ['confirm' => true])->assertForbidden();
        $this->postJson(route('admin.whatsapp.pairing-code'))->assertForbidden();
        $this->postJson(route('admin.whatsapp.send-test'))->assertForbidden();
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_a_recent_verification_unlocks_the_sensitive_actions(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->withSession([AdminMfaService::SESSION_KEY => ['user_id' => $admin->id, 'at' => now()->getTimestamp()]]);

        $this->getJson(route('admin.whatsapp.status'))->assertOk()->assertJsonPath('stepUp.valid', true);
    }
}
