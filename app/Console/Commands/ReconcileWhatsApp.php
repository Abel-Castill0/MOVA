<?php

namespace App\Console\Commands;

use App\Support\WhatsAppReconciliation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Observabilidad de WhatsApp — mismo espíritu que mova:reconcile-ledger.
 *
 * SOLO LECTURA: nunca reintenta un envío, nunca cambia un estado, nunca
 * consulta a Meta. Señala qué mensajes quedaron en un estado que no
 * debería durar ('unknown' sin resolver, o 'sent' sin webhook de estado
 * durante demasiado tiempo) para que un humano decida qué hacer.
 */
class ReconcileWhatsApp extends Command
{
    protected $signature = 'mova:reconcile-whatsapp
        {--json : Devuelve el informe completo en JSON}
        {--stuck-minutes=60 : Minutos en "sent" sin webhook antes de señalar para revisión}';

    protected $description = 'Reconcilia whatsapp_messages en busca de entregas inciertas o estancadas (solo lectura)';

    public function handle(): int
    {
        $reconciliation = new WhatsAppReconciliation((int) $this->option('stuck-minutes'));
        $report = $reconciliation->run();

        // Log estructurado además de la salida por consola — para cuando
        // este comando corra en un scheduler/cron y nadie esté mirando la
        // terminal. Claves planas (no anidadas) a propósito, para que
        // cualquier agregador de logs las pueda indexar sin parseo extra.
        Log::info('whatsapp_reconciliation', [
            'total_messages' => $report['total_messages'],
            'needs_attention_count' => count($report['needs_attention']),
            'unknown_still_waiting' => $report['unknown_still_waiting'],
            'healthy' => $report['healthy'],
        ]);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $report['healthy'] ? self::SUCCESS : self::FAILURE;
        }

        $this->renderHuman($report);

        return $report['healthy'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderHuman(array $report): void
    {
        $this->newLine();
        $this->line("Mensajes totales: {$report['total_messages']}");
        if ($report['unknown_still_waiting'] > 0) {
            $this->line("Esperando webhook (unknown, bajo el umbral de {$report['stuck_sent_threshold_minutes']} min): {$report['unknown_still_waiting']}");
        }
        $this->newLine();

        $rows = [];
        foreach ($report['counts'] as $status => $count) {
            $rows[] = [$status, $count];
        }
        $this->table(['Estado', 'Mensajes'], $rows);

        if ($report['needs_attention'] !== []) {
            $this->newLine();
            $this->error('MENSAJES QUE REQUIEREN REVISIÓN');
            $this->table(
                ['ID', 'Para', 'Plantilla', 'wamid', 'Referencia', 'Edad (min)', 'Motivo'],
                array_map(fn ($m) => [
                    $m['id'],
                    $m['to'],
                    $m['template_key'],
                    $m['provider_message_id'] ?? '—',
                    $m['client_reference'] ?? '—',
                    $m['age_minutes'],
                    $m['reason'],
                ], $report['needs_attention'])
            );
        }

        $this->newLine();

        if ($report['healthy']) {
            $this->info('GREEN — sin mensajes en estado incierto o estancado.');

            return;
        }

        $this->error(sprintf(
            'ATENCIÓN — %d mensaje(s) requieren revisión manual.',
            count($report['needs_attention'])
        ));
    }
}
