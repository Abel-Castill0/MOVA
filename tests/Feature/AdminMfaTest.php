<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AdminMfaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminMfaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $bypassAdminMfa = false;

    private function admin(): User
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function enroll(User $admin): string
    {
        $secret = (new Google2FA())->generateSecretKey(32);
        $admin->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

        return $secret;
    }

    public function test_unenrolled_admin_is_forced_to_setup_on_admin_routes_and_actions(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.users'))->assertRedirect(route('admin.mfa.setup'));
        $this->actingAs($admin)->get(route('dashboard'))->assertRedirect(route('admin.mfa.setup'));
        $this->actingAs($admin)->post(route('admin.users.suspend', $admin))->assertRedirect(route('admin.mfa.setup'));
    }

    public function test_enrolled_admin_without_session_verification_gets_challenge(): void
    {
        $admin = $this->admin();
        $this->enroll($admin);

        $this->actingAs($admin)->get(route('admin.recharges.index'))->assertRedirect(route('admin.mfa.challenge'));
    }

    public function test_non_admins_are_unaffected(): void
    {
        Role::findOrCreate('parent', 'web');
        $parent = User::factory()->create();
        $parent->assignRole('parent');

        $this->actingAs($parent)->get(route('dashboard'))->assertOk();
        $this->actingAs($parent)->get(route('admin.mfa.setup'))->assertForbidden();
    }

    public function test_full_enrollment_flow_returns_one_time_recovery_codes(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.mfa.setup'))->assertOk();
        $secret = session('admin_mfa_pending_secret');
        $this->assertNull($admin->fresh()->two_factor_secret, 'El secret no se persiste antes de confirmar.');

        $this->actingAs($admin)->post(route('admin.mfa.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');

        $code = (new Google2FA())->getCurrentOtp($secret);
        $this->actingAs($admin)->post(route('admin.mfa.confirm'), ['code' => $code])
            ->assertRedirect(route('admin.mfa.recovery-codes'));

        $fresh = $admin->fresh();
        $this->assertNotNull($fresh->two_factor_confirmed_at);
        $this->assertCount(8, $fresh->two_factor_recovery_codes);
        $this->assertStringNotContainsString($secret, (string) $fresh->getRawOriginal('two_factor_secret'), 'Secret cifrado en reposo.');
        $this->assertArrayNotHasKey('two_factor_secret', $fresh->toArray());

        $this->actingAs($admin)->get(route('admin.users'))->assertOk();
    }

    public function test_challenge_accepts_totp_once_and_rejects_replay(): void
    {
        $admin = $this->admin();
        $secret = $this->enroll($admin);
        $code = (new Google2FA())->getCurrentOtp($secret);

        $this->actingAs($admin)->post(route('admin.mfa.verify'), ['code' => $code])->assertRedirect();
        $this->actingAs($admin)->get(route('admin.users'))->assertOk();

        $this->flushSession();
        $this->actingAs($admin)->post(route('admin.mfa.verify'), ['code' => $code])->assertSessionHasErrors('code');
    }

    // P0-01: el anti-replay vive en la fila del usuario, no en cache/proceso.
    public function test_replay_guard_is_persisted_and_survives_cache_loss(): void
    {
        $admin = $this->admin();
        $secret = $this->enroll($admin);
        $code = (new Google2FA())->getCurrentOtp($secret);
        $service = app(AdminMfaService::class);

        $this->assertTrue($service->verify($admin, $code));
        $this->assertNotNull($admin->fresh()->two_factor_last_used_timestep);

        \Illuminate\Support\Facades\Cache::flush();
        $this->assertFalse($service->verify(User::find($admin->id), $code), 'Otro worker (instancia fresca, sin cache) no puede reutilizar el código.');
    }

    public function test_concurrent_second_confirm_cannot_reenroll_or_replace_recovery_codes(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.mfa.setup'))->assertOk();
        $secret = session('admin_mfa_pending_secret');
        $code = (new Google2FA())->getCurrentOtp($secret);

        $request = request();
        $request->setLaravelSession(app('session.store'));
        $service = app(AdminMfaService::class);

        $first = $service->confirm($admin, $request, $code);
        $this->assertCount(8, $first);
        $hashes = $admin->fresh()->two_factor_recovery_codes;

        // Segunda confirmación "concurrente" con el mismo secret pendiente y código.
        $request->session()->put('admin_mfa_pending_secret', $secret);
        $this->assertNull($service->confirm(User::find($admin->id), $request, $code));
        $this->assertSame($hashes, $admin->fresh()->two_factor_recovery_codes, 'Los recovery codes mostrados no pueden ser reemplazados.');
    }

    public function test_recovery_code_is_single_use(): void
    {
        $admin = $this->admin();
        $this->enroll($admin);
        $codes = app(AdminMfaService::class)->regenerateRecoveryCodes($admin);

        $this->actingAs($admin)->post(route('admin.mfa.verify'), ['code' => $codes[0]])->assertSessionHasNoErrors();
        $this->assertCount(7, $admin->fresh()->two_factor_recovery_codes);

        $this->flushSession();
        $this->actingAs($admin)->post(route('admin.mfa.verify'), ['code' => $codes[0]])->assertSessionHasErrors('code');
    }

    public function test_challenge_is_rate_limited(): void
    {
        $admin = $this->admin();
        $secret = $this->enroll($admin);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($admin)->post(route('admin.mfa.verify'), ['code' => '000000']);
        }

        $valid = (new Google2FA())->getCurrentOtp($secret);
        $this->actingAs($admin)->post(route('admin.mfa.verify'), ['code' => $valid])->assertSessionHasErrors('code');
        $this->actingAs($admin)->get(route('admin.users'))->assertRedirect(route('admin.mfa.challenge'));
    }

    public function test_sensitive_actions_require_recent_step_up(): void
    {
        $admin = $this->admin();
        $this->enroll($admin);
        $stale = now()->subMinutes(30)->getTimestamp();

        $this->actingAs($admin)
            ->withSession([AdminMfaService::SESSION_KEY => ['user_id' => $admin->id, 'at' => $stale]])
            ->get(route('admin.users'))->assertOk();

        $this->actingAs($admin)
            ->withSession([AdminMfaService::SESSION_KEY => ['user_id' => $admin->id, 'at' => $stale]])
            ->post(route('admin.users.unsuspend', $admin))
            ->assertRedirect(route('admin.mfa.challenge'));
    }

    public function test_session_verification_is_bound_to_the_user(): void
    {
        $admin = $this->admin();
        $this->enroll($admin);

        $this->actingAs($admin)
            ->withSession([AdminMfaService::SESSION_KEY => ['user_id' => $admin->id + 999, 'at' => now()->getTimestamp()]])
            ->get(route('admin.users'))->assertRedirect(route('admin.mfa.challenge'));
    }

    public function test_break_glass_reset_command(): void
    {
        $admin = $this->admin();
        $this->enroll($admin);

        $this->artisan('mova:admin-mfa-reset', ['email' => $admin->email, '--force' => true])->assertSuccessful();
        $this->assertNull($admin->fresh()->two_factor_confirmed_at);
    }
}
