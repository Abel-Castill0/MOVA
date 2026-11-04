<?php

namespace Tests\Feature;

use App\Console\Commands\WhatsAppProbe;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\WhatsApp\Waha\WahaSessionState;
use App\WhatsApp\WhatsAppMessageStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** La sonda solo escribe a destinatarios de la lista autorizada y pasa por el canal real (con todas sus verificaciones). */
class WhatsAppProbeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        RateLimiter::clear('waha:global');
        config([
            'services.waha.require_user_initiated' => false,
            'services.waha.warmup_minutes' => 0,
            'services.whatsapp.enabled' => true,
            'services.whatsapp.provider' => 'waha',
            'services.waha.url' => 'http://127.0.0.1:3000',
            'services.waha.api_key' => 'k',
            'services.waha.hmac_key' => 'h',
            'services.waha.typing_min_ms' => 0,
            'services.waha.typing_max_ms' => 0,
            'services.waha.rate_wait_seconds' => 0,
            'services.waha.allowed_recipients' => '51916666934',
            'services.waha.rate_per_minute' => 100,
        ]);
        WahaSessionState::record('WORKING');
        $n = 0;
        Http::fake(function () use (&$n) {
            $n++;

            return Http::response(['id' => 'true_51916666934@c.us_3EB0PROBE'.$n], 201);
        });
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'phone' => '916666934',
            'phone_verified_at' => now(),
            'phone_verified_normalized' => '+51916666934',
            'whatsapp_opt_in_at' => now(),
        ], $attributes));
    }

    public function test_it_refuses_without_an_allowlist(): void
    {
        config(['services.waha.allowed_recipients' => '']);

        $this->artisan('mova:whatsapp-probe', ['user' => $this->user()->id])->assertFailed();
        Http::assertNothingSent();
    }

    public function test_it_refuses_a_recipient_outside_the_allowlist(): void
    {
        $other = $this->user(['phone' => '987654321', 'phone_verified_normalized' => '+51987654321']);

        $this->artisan('mova:whatsapp-probe', ['user' => $other->id])->assertFailed();
        Http::assertNothingSent();
    }

    public function test_it_refuses_when_whatsapp_is_disabled(): void
    {
        config(['services.whatsapp.enabled' => false]);

        $this->artisan('mova:whatsapp-probe', ['user' => $this->user()->id])->assertFailed();
        Http::assertNothingSent();
    }

    public function test_every_template_goes_through_the_channel_without_touching_business_tables(): void
    {
        $user = $this->user();

        $this->artisan('mova:whatsapp-probe', ['user' => $user->id, '--pause' => 0])->assertSuccessful();

        $this->assertSame(count(WhatsAppProbe::EVENTS), WhatsAppMessage::where('status', WhatsAppMessageStatus::Sent)->count());
        $this->assertDatabaseCount('classes', 0);
        $this->assertDatabaseCount('class_requests', 0);
        $this->assertDatabaseCount('recharge_requests', 0);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/sendText'));
    }

    public function test_consent_still_applies(): void
    {
        $user = $this->user(['whatsapp_opt_in_at' => null]);

        $this->artisan('mova:whatsapp-probe', ['user' => $user->id, '--event' => ['class_confirmed']])->assertSuccessful();

        $this->assertSame(0, WhatsAppMessage::where('status', WhatsAppMessageStatus::Sent)->count());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/api/sendText'));
    }
}
