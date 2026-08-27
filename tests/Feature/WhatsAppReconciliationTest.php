<?php

namespace Tests\Feature;

use App\Models\WhatsAppMessage;
use App\Support\WhatsAppReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Cubre mova:reconcile-whatsapp / WhatsAppReconciliation — mismo espíritu
 * que LedgerReconciliationTest para el ledger financiero: solo lectura,
 * nunca corrige nada, solo señala qué requiere revisión humana.
 */
class WhatsAppReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_healthy_when_there_is_nothing_to_flag(): void
    {
        WhatsAppMessage::create($this->attrs('wamid.1', 'delivered'));

        $report = (new WhatsAppReconciliation())->run();

        $this->assertTrue($report['healthy']);
        $this->assertSame([], $report['needs_attention']);
    }

    public function test_flags_unknown_status_messages_past_the_threshold(): void
    {
        Carbon::setTestNow('2026-08-23 10:00:00');
        WhatsAppMessage::create($this->attrs('wamid.2', 'unknown'));

        Carbon::setTestNow('2026-08-23 11:30:00'); // 90 min después, sobre el umbral de 60

        $report = (new WhatsAppReconciliation(stuckSentMinutes: 60))->run();

        $this->assertFalse($report['healthy']);
        $this->assertCount(1, $report['needs_attention']);
        $this->assertSame('unknown_delivery', $report['needs_attention'][0]['reason']);
    }

    /**
     * Política explícita para 'unknown' (ver el comentario en
     * WhatsAppReconciliation): un mensaje recién marcado 'unknown' está
     * legítimamente esperando el webhook de Meta — no debería aparecer
     * como "requiere revisión" a los pocos segundos de haberse enviado.
     */
    public function test_does_not_flag_recently_unknown_messages_but_counts_them_as_waiting(): void
    {
        WhatsAppMessage::create($this->attrs('wamid.12', 'unknown'));

        $report = (new WhatsAppReconciliation(stuckSentMinutes: 60))->run();

        $this->assertTrue($report['healthy']);
        $this->assertSame([], $report['needs_attention']);
        $this->assertSame(1, $report['unknown_still_waiting']);
    }

    public function test_flags_messages_stuck_in_sent_past_the_threshold(): void
    {
        Carbon::setTestNow('2026-08-23 10:00:00');
        WhatsAppMessage::create($this->attrs('wamid.3', 'sent'));

        Carbon::setTestNow('2026-08-23 11:30:00'); // 90 min después

        $report = (new WhatsAppReconciliation(stuckSentMinutes: 60))->run();

        $this->assertFalse($report['healthy']);
        $this->assertSame('stuck_in_sent', $report['needs_attention'][0]['reason']);
        $this->assertSame(90, $report['needs_attention'][0]['age_minutes']);
    }

    public function test_does_not_flag_recently_sent_messages(): void
    {
        Carbon::setTestNow('2026-08-23 10:00:00');
        WhatsAppMessage::create($this->attrs('wamid.4', 'sent'));

        Carbon::setTestNow('2026-08-23 10:10:00'); // 10 min después, bajo el umbral de 60

        $report = (new WhatsAppReconciliation(stuckSentMinutes: 60))->run();

        $this->assertTrue($report['healthy']);
    }

    public function test_does_not_flag_failed_messages(): void
    {
        // 'failed' es un resultado ya conocido — no necesita revisión, a
        // diferencia de 'unknown' (resultado incierto).
        WhatsAppMessage::create($this->attrs('wamid.5', 'failed'));

        $report = (new WhatsAppReconciliation())->run();

        $this->assertTrue($report['healthy']);
    }

    public function test_counts_messages_by_status(): void
    {
        WhatsAppMessage::create($this->attrs('wamid.6', 'sent'));
        WhatsAppMessage::create($this->attrs('wamid.7', 'delivered'));
        WhatsAppMessage::create($this->attrs('wamid.8', 'delivered'));

        $report = (new WhatsAppReconciliation())->run();

        $this->assertSame(3, $report['total_messages']);
        $this->assertSame(1, $report['counts']['sent']);
        $this->assertSame(2, $report['counts']['delivered']);
    }

    public function test_artisan_command_exits_with_failure_code_when_unhealthy(): void
    {
        Carbon::setTestNow('2026-08-23 10:00:00');
        WhatsAppMessage::create($this->attrs('wamid.9', 'unknown'));
        Carbon::setTestNow('2026-08-23 12:00:00'); // 2h después, sobre el umbral por defecto (60 min)

        $this->artisan('mova:reconcile-whatsapp --json')->assertExitCode(1);
    }

    public function test_artisan_command_exits_with_success_code_when_healthy(): void
    {
        $this->artisan('mova:reconcile-whatsapp --json')->assertExitCode(0);
    }

    private function attrs(string $wamid, string $status): array
    {
        return [
            'to' => '+51987654321',
            'template_key' => 'generic_notification',
            'provider' => 'meta',
            'provider_message_id' => $wamid,
            'status' => $status,
        ];
    }
}
