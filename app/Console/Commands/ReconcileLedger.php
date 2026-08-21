<?php

namespace App\Console\Commands;

use App\Support\LedgerReconciliation;
use Illuminate\Console\Command;

/**
 * Observabilidad financiera permanente de MOVA.
 *
 * SOLO LECTURA: no ejecuta UPDATE, INSERT, DELETE ni transacciones de
 * escritura, y nunca corrige una anomalía. Detectar y corregir son
 * operaciones distintas: fabricar un asiento para "cuadrar" un descuadre
 * ocultaría la causa en vez de resolverla.
 *
 * Devuelve código de salida distinto de 0 cuando encuentra anomalías, para
 * poder integrarlo en CI, en un paso previo al despliegue o en monitorización.
 */
class ReconcileLedger extends Command
{
    protected $signature = 'mova:reconcile-ledger {--json : Devuelve el informe completo en JSON}';

    protected $description = 'Reconcilia credit_transactions contra classes y teacher_profiles (solo lectura)';

    public function handle(LedgerReconciliation $reconciliation): int
    {
        $report = $reconciliation->run();

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
        $this->line("Lecciones examinadas: {$report['total_lessons']}");
        $this->newLine();

        $rows = [];
        foreach ($report['counts'] as $classification => $count) {
            $rows[] = [$classification, $count];
        }
        $this->table(['Clasificación', 'Lecciones'], $rows);

        if ($report['anomalies'] !== []) {
            $this->newLine();
            $this->error('ANOMALÍAS POR LECCIÓN');
            $this->table(
                ['Lesson', 'Profesor', 'Estado', 'Resv', 'Cons', 'Devol', 'Clasificación', 'Transacciones'],
                array_map(fn ($a) => [
                    $a['lesson_id'],
                    $a['teacher_profile_id'],
                    $a['status'],
                    $a['reservations'],
                    $a['consumptions'],
                    $a['refunds'],
                    $a['classification'],
                    implode(',', $a['transaction_ids']) ?: '—',
                ], $report['anomalies'])
            );

            foreach ($report['anomalies'] as $anomaly) {
                $this->line("  Lesson {$anomaly['lesson_id']}: {$anomaly['reason']}");
            }
        }

        if ($report['profile_mismatches'] !== []) {
            $this->newLine();
            $this->error('DESCUADRES DE SALDO');
            $this->table(
                ['Profesor', 'reserved (guardado)', 'reserved (ledger)', 'available (guardado)', 'available (ledger)'],
                array_map(fn ($p) => [
                    $p['teacher_profile_id'],
                    $p['credits_reserved_stored'].($p['reserved_ok'] ? '' : ' ✗'),
                    $p['credits_reserved_derived'],
                    $p['credits_available_stored'].($p['available_ok'] ? '' : ' ✗'),
                    $p['credits_available_derived'],
                ], $report['profile_mismatches'])
            );
        }

        $this->newLine();

        if ($report['healthy']) {
            $this->info('GREEN — el ledger explica todos los saldos y no hay lecciones anómalas.');

            return;
        }

        $this->error(sprintf(
            'RED — %d lección(es) anómala(s), %d profesor(es) descuadrado(s). No se corrigió nada.',
            count($report['anomalies']),
            count($report['profile_mismatches'])
        ));
    }
}
