<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Páginas de la campaña física con QR: /profe y /familias. */
class QrCampaignLandingTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function metric(string $campaign, string $event): int
    {
        return (int) DB::table('campaign_metrics')->where('campaign', $campaign)->where('event', $event)->sum('count');
    }

    public function test_both_pages_are_public_and_carry_their_own_video_poster_and_captions(): void
    {
        foreach (['profe' => 'teacher', 'familias' => 'parent'] as $campaign => $audience) {
            $props = $this->withHeader('User-Agent', self::PHONE)->get("/{$campaign}")->assertOk()->inertiaPage();
            $this->assertSame('Landing/QrCampaign', $props['component']);
            $this->assertSame($audience, $props['props']['audience']);
            $this->assertSame("/media/campana/{$campaign}.mp4", $props['props']['video']);
            $this->assertSame("/media/campana/{$campaign}-poster.jpg", $props['props']['poster']);
            $this->assertSame("/media/campana/{$campaign}.vtt", $props['props']['captions']);
            $this->assertStringEndsWith("/{$campaign}/empezar", $props['props']['startUrl']);
            $this->assertFileExists(public_path("media/campana/{$campaign}.mp4"));
            $this->assertFileExists(public_path("media/campana/{$campaign}-poster.jpg"));
            $this->assertStringStartsWith('WEBVTT', file_get_contents(public_path("media/campana/{$campaign}.vtt")));
        }
    }

    public function test_the_start_button_goes_home_with_the_campaign_utm(): void
    {
        $this->withHeader('User-Agent', self::PHONE)->get('/profe/empezar')
            ->assertStatus(302)->assertHeader('Location', url('/').'/?utm_source=qr_tarjeta&utm_medium=offline&utm_campaign=profesores');
        $this->withHeader('User-Agent', self::PHONE)->get('/familias/empezar')
            ->assertStatus(302)->assertHeader('Location', url('/').'/?utm_source=qr_volante&utm_medium=offline&utm_campaign=padres');
    }

    public function test_visits_plays_and_clicks_are_counted_per_page_and_per_lima_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-22 23:30', 'America/Lima')->utc()); // en UTC ya es el 23

        $this->withHeader('User-Agent', self::PHONE)->get('/profe');
        $this->withHeader('User-Agent', self::PHONE)->get('/profe');
        $this->withHeader('User-Agent', self::PHONE)->postJson('/profe/evento', ['event' => 'play'])->assertNoContent();
        $this->withHeader('User-Agent', self::PHONE)->get('/profe/empezar');
        $this->withHeader('User-Agent', self::PHONE)->get('/familias');

        $this->assertSame(2, $this->metric('profe', 'visit'));
        $this->assertSame(1, $this->metric('profe', 'play'));
        $this->assertSame(1, $this->metric('profe', 'cta'));
        $this->assertSame(1, $this->metric('familias', 'visit'));
        $this->assertSame(0, $this->metric('familias', 'play'));
        $this->assertSame(['2026-11-22'], DB::table('campaign_metrics')->distinct()->pluck('day')->map(fn ($d) => substr((string) $d, 0, 10))->all());
        $this->assertSame(3, DB::table('campaign_metrics')->where('campaign', 'profe')->count(), 'Una fila por día y evento: solo contadores.');
    }

    public function test_bots_and_link_previews_are_not_counted_but_still_get_the_page(): void
    {
        foreach (['WhatsApp/2.23.20.0', 'facebookexternalhit/1.1', 'Googlebot/2.1', 'curl/8.4.0', ''] as $agent) {
            $this->withHeader('User-Agent', $agent)->get('/familias')->assertOk();
        }
        $this->withHeaders(['User-Agent' => self::PHONE, 'Sec-Purpose' => 'prefetch'])->get('/familias')->assertOk();

        $this->assertSame(0, $this->metric('familias', 'visit'));
    }

    public function test_only_the_play_event_is_accepted_and_unknown_campaigns_do_not_exist(): void
    {
        $this->withHeader('User-Agent', self::PHONE)->postJson('/profe/evento', ['event' => 'cta'])->assertStatus(422);
        $this->withHeader('User-Agent', self::PHONE)->postJson('/profe/evento', [])->assertStatus(422);
        $this->get('/otra')->assertNotFound();
        $this->get('/otra/empezar')->assertNotFound();
        $this->assertSame(0, DB::table('campaign_metrics')->count());
    }

    public function test_the_stats_command_summarizes_both_pages(): void
    {
        $this->withHeader('User-Agent', self::PHONE)->get('/profe');
        $this->withHeader('User-Agent', self::PHONE)->get('/profe/empezar');

        $this->artisan('mova:campaign-stats')
            ->expectsTable(['Página', 'Visitas', 'Play', 'Empieza ahora', 'Clic / visita'], [
                ['/profe', 1, 0, 1, '100 %'],
                ['/familias', 0, 0, 0, '—'],
            ])
            ->assertExitCode(0);
    }
}
