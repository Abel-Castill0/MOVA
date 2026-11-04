<?php

namespace Tests\Feature;

use App\Models\User;
use App\WhatsApp\Contracts\WhatsAppProviderContract;
use App\WhatsApp\FakeWhatsAppProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Primer envío del OTP sin pulsar «Reenviar código»: la pantalla lo pide sola (POST `auto`), con el mismo endpoint, límites
 * y bloqueo que el reenvío, sin generar códigos de más ni invalidar uno vigente por un doble clic o una carrera.
 */
class PhoneVerificationInitialSendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['parent', 'teacher', 'admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        config(['services.whatsapp.enabled' => true]);
    }

    private function user(string $role = 'parent', string $phone = '+51987654321'): User
    {
        $user = User::factory()->create(['phone' => $phone, 'phone_verified_at' => null]);
        $user->assignRole($role);

        return $user;
    }

    private function provider(): FakeWhatsAppProvider
    {
        return app(WhatsAppProviderContract::class);
    }

    public function test_opening_the_screen_never_sends_by_itself_and_asks_for_the_first_code(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get(route('phone.verification.notice'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/PhoneVerification')
                ->where('hasPhone', true)->where('verified', false)->where('pending', false)->where('cooldown', 0));

        $this->assertCount(0, $this->provider()->sent, 'un GET no debe enviar nada');
    }

    public function test_first_automatic_send_generates_one_code_and_sends_it(): void
    {
        $user = $this->user();

        $this->actingAs($user)->post(route('phone.verification.send'), ['auto' => true])
            ->assertSessionHas('status', 'phone-verification-sent')->assertSessionDoesntHaveErrors();

        $this->assertCount(1, $this->provider()->sent);
        $this->assertSame('phone_verification_code', $this->provider()->sent[0]['template']);
        $fresh = $user->fresh();
        $this->assertNotNull($fresh->phone_verification_code_hash);
        $this->assertTrue($fresh->phone_verification_expires_at->between(now()->addMinutes(9), now()->addMinutes(10)));

        // Tras el envío la pantalla sabe que hay un código vigente y cuánto falta para poder reenviar.
        $this->actingAs($user)->get(route('phone.verification.notice'))
            ->assertInertia(fn (Assert $page) => $page->where('pending', true)->where('cooldown', fn ($s) => $s > 0 && $s <= 30));
    }

    public function test_reloading_or_double_click_does_not_generate_a_second_code(): void
    {
        $user = $this->user();
        $code = $this->actingAs($user)->post(route('phone.verification.send'), ['auto' => true])->getSession()->get('debugCode');
        $hash = $user->fresh()->phone_verification_code_hash;

        $this->actingAs($user)->post(route('phone.verification.send'), ['auto' => true])
            ->assertSessionHas('status', 'phone-verification-pending');
        $this->travel(5)->minutes();
        $this->actingAs($user)->post(route('phone.verification.send'), ['auto' => true])
            ->assertSessionHas('status', 'phone-verification-pending');

        $this->assertCount(1, $this->provider()->sent);
        $this->assertSame($hash, $user->fresh()->phone_verification_code_hash);
        $this->actingAs($user)->post(route('phone.verification.verify'), ['code' => $code])->assertSessionDoesntHaveErrors();
        $this->assertNotNull($user->fresh()->phone_verified_at);
    }

    public function test_immediate_resend_cannot_invalidate_the_code_just_sent(): void
    {
        $user = $this->user();
        $code = $this->actingAs($user)->post(route('phone.verification.send'), ['auto' => true])->getSession()->get('debugCode');

        $this->actingAs($user)->post(route('phone.verification.send'))->assertSessionHasErrors('resend');

        $this->assertCount(1, $this->provider()->sent);
        $this->actingAs($user)->post(route('phone.verification.verify'), ['code' => $code])->assertSessionDoesntHaveErrors();
    }

    public function test_resend_after_the_wait_sends_a_new_code_and_retires_the_old_one(): void
    {
        $user = $this->user();
        $old = $this->actingAs($user)->post(route('phone.verification.send'), ['auto' => true])->getSession()->get('debugCode');

        $this->travel(31)->seconds();
        $new = $this->actingAs($user)->post(route('phone.verification.send'))
            ->assertSessionHas('status', 'phone-verification-sent')->getSession()->get('debugCode');

        $this->assertCount(2, $this->provider()->sent);
        if ($old !== $new) {
            $this->actingAs($user)->post(route('phone.verification.verify'), ['code' => $old])->assertSessionHasErrors('code');
        }
        $this->actingAs($user)->post(route('phone.verification.verify'), ['code' => $new])->assertSessionDoesntHaveErrors();
    }

    public function test_concurrent_send_while_another_is_in_flight_does_nothing(): void
    {
        $user = $this->user();
        $lock = Cache::lock('phone-otp-send:'.$user->id, 20);
        $this->assertTrue($lock->get());

        $this->actingAs($user)->post(route('phone.verification.send'))
            ->assertSessionHas('status', 'phone-verification-in-progress');

        $this->assertCount(0, $this->provider()->sent);
        $this->assertNull($user->fresh()->phone_verification_code_hash);
        $lock->release();
    }

    public function test_wrong_and_expired_codes_are_rejected(): void
    {
        $user = $this->user();
        $code = $this->actingAs($user)->post(route('phone.verification.send'), ['auto' => true])->getSession()->get('debugCode');

        $wrong = $code === '000000' ? '111111' : '000000';
        $this->actingAs($user)->post(route('phone.verification.verify'), ['code' => $wrong])->assertSessionHasErrors('code');
        $this->assertSame(1, $user->fresh()->phone_verification_attempts);

        $this->travel(11)->minutes();
        $this->actingAs($user)->post(route('phone.verification.verify'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertNull($user->fresh()->phone_verified_at);

        // Caducado: la pantalla vuelve a pedir uno nuevo automáticamente.
        $this->actingAs($user)->get(route('phone.verification.notice'))
            ->assertInertia(fn (Assert $page) => $page->where('pending', false)->where('cooldown', 0));
    }

    public function test_provider_failure_shows_a_clear_error_and_allows_an_immediate_safe_retry(): void
    {
        $this->app['env'] = 'production';
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class); // fuera de `testing` Laravel vuelve a exigir CSRF
        $failing = new class implements WhatsAppProviderContract {
            public int $calls = 0;

            public function sendTemplate(string $to, string $templateKey, array $params, ?string $clientReference = null): bool
            {
                $this->calls++;

                return false;
            }
        };
        $this->app->instance(WhatsAppProviderContract::class, $failing);
        $user = $this->user();

        $r = $this->actingAs($user)->post(route('phone.verification.send'), ['auto' => true]);
        $r->assertSessionHasErrors('phone')->assertSessionMissing('debugCode');
        $this->actingAs($user)->get(route('phone.verification.notice'))
            ->assertInertia(fn (Assert $page) => $page->where('pending', false)->where('cooldown', 0));

        $this->actingAs($user)->post(route('phone.verification.send'))->assertSessionHasErrors('phone');
        $this->assertSame(2, $failing->calls, 'tras un fallo se puede reintentar sin esperar');
    }

    public function test_exhausted_attempts_lock_the_automatic_send(): void
    {
        $user = $this->user();
        $code = $this->actingAs($user)->post(route('phone.verification.send'), ['auto' => true])->getSession()->get('debugCode');
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->post(route('phone.verification.verify'), ['code' => $wrong]);
        }
        $this->travel(61)->seconds(); // pasada la espera y el throttle por usuario (cubo compartido entre rutas)

        $this->actingAs($user)->get(route('phone.verification.notice'))
            ->assertInertia(fn (Assert $page) => $page->where('locked', true)->where('pending', false));
        $this->actingAs($user)->post(route('phone.verification.send'), ['auto' => true])->assertSessionHasErrors('code');
        $this->assertCount(1, $this->provider()->sent);
    }

    public function test_changing_the_phone_clears_the_wait_and_the_old_code_cannot_verify_the_new_number(): void
    {
        $user = $this->user();
        $old = $this->actingAs($user)->post(route('phone.verification.send'), ['auto' => true])->getSession()->get('debugCode');

        // Lo que hace ProfileController al cambiar el número: nuevo teléfono y verificación reiniciada.
        $user->forceFill(['phone' => '+51912345678', 'phone_verification_code_hash' => null, 'phone_verification_expires_at' => null])->save();

        $this->actingAs($user)->get(route('phone.verification.notice'))
            ->assertInertia(fn (Assert $page) => $page->where('pending', false)->where('cooldown', 0));
        $this->actingAs($user)->post(route('phone.verification.verify'), ['code' => $old])->assertSessionHasErrors('code');

        $this->actingAs($user)->post(route('phone.verification.send'), ['auto' => true])->assertSessionHas('status', 'phone-verification-sent');
        $this->assertSame('+51912345678', $this->provider()->sent[1]['to']);
    }

    public function test_otp_is_sent_synchronously_and_never_depends_on_the_queue(): void
    {
        Queue::fake();
        $user = $this->user();

        $this->actingAs($user)->post(route('phone.verification.send'), ['auto' => true])
            ->assertSessionHas('status', 'phone-verification-sent');

        $this->assertCount(1, $this->provider()->sent);
        Queue::assertNothingPushed();
    }

    public function test_per_number_limit_still_applies_to_the_automatic_send(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->user())->post(route('phone.verification.send'), ['auto' => true])->assertSessionDoesntHaveErrors();
        }

        $this->actingAs($this->user())->post(route('phone.verification.send'), ['auto' => true])->assertSessionHasErrors('phone');
        $this->assertCount(5, $this->provider()->sent);
    }

    public function test_route_throttle_still_applies(): void
    {
        $user = $this->user();
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user)->post(route('phone.verification.send'), ['auto' => true]);
        }
        $this->actingAs($user)->post(route('phone.verification.send'), ['auto' => true])->assertStatus(429);
    }

    public function test_admin_can_verify_without_enabling_business_notifications(): void
    {
        $admin = $this->user('admin');
        $code = $this->actingAs($admin)->post(route('phone.verification.send'), ['auto' => true])->getSession()->get('debugCode');

        $this->actingAs($admin)->post(route('phone.verification.verify'), ['code' => $code])->assertSessionDoesntHaveErrors();

        $fresh = $admin->fresh();
        $this->assertNotNull($fresh->phone_verified_at);
        $this->assertNull($fresh->whatsapp_opt_in_at);
        $this->assertFalse($fresh->wantsWhatsAppNotifications());
    }

    public function test_already_verified_user_gets_no_code(): void
    {
        $user = $this->user();
        $user->forceFill(['phone_verified_at' => now()])->save();

        $this->actingAs($user)->post(route('phone.verification.send'), ['auto' => true])
            ->assertSessionHas('status', 'phone-already-verified');
        $this->assertCount(0, $this->provider()->sent);
    }

    public function test_the_code_never_appears_in_production_responses(): void
    {
        $this->app['env'] = 'production';
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class); // fuera de `testing` Laravel vuelve a exigir CSRF
        $fake = new FakeWhatsAppProvider;
        $this->app->instance(WhatsAppProviderContract::class, $fake);
        $user = $this->user();

        $response = $this->actingAs($user)->post(route('phone.verification.send'), ['auto' => true]);

        $response->assertSessionHas('status', 'phone-verification-sent')->assertSessionMissing('debugCode');
        $code = $fake->sent[0]['params'][0];
        $this->assertTrue(Hash::check($code, $user->fresh()->phone_verification_code_hash));
        $this->assertStringNotContainsString($code, (string) $response->getContent());
    }
}
