<?php

namespace App\Console\Commands;

use App\Models\OperationalAlert;
use App\Services\OperationalAlertService;
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
    protected $signature = 'mova:reconcile-ledger
        {--json : Devuelve el informe completo en JSON}
        {--alert : Registra una incidencia si el ledger no cuadra, para que los administradores reciban aviso (lo usa el scheduler)}';

    protected $description = 'Reconcilia credit_transactions contra classes y teacher_profiles (solo lectura)';

    public function handle(LedgerReconciliation $reconciliation): int
    {
        $report = $reconciliation->run();

        // SIGUE SIENDO SOLO LECTURA. `--alert` no corrige ni un céntimo: lo
        // único que escribe es la fila de incidencia que hace visible el
        // descuadre. Un ledger descuadrado exige investigación manual, nunca
        // un ajuste automático (CLAUDE.md §2).
        $this->publishFindings($report);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $report['healthy'] ? self::SUCCESS : self::FAILURE;
        }

        $this->renderHuman($report);

        return $report['healthy'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Una sola incidencia agregada, no una por lección anómala.
     *
     * Un descuadre suele afectar a varias filas a la vez (un profesor con el
     * saldo desviado arrastra todas sus clases), y abrir N incidencias por el
     * mismo episodio es exactamente el spam que la tabla existe para evitar.
     * La clave es fija: mientras el ledger siga descuadrado, es LA MISMA
     * incidencia, y el detalle por lección vive en la salida del comando.
     */
    private function publishFindings(array $report): void
    {
        if (! $this->option('alert')) {
            return;
        }

        $alerts = app(OperationalAlertService::class);
        $key = 'ledger:anomaly';

        if ($report['healthy']) {
            $alerts->resolve($key);

            return;
        }

        $alerts->raise(
            key: $key,
            type: OperationalAlert::TYPE_LEDGER_ANOMALY,
            title: 'El ledger de créditos no cuadra',
            message: 'La reconciliación encontró movimientos de crédito que no explican los saldos '
                .'almacenados. MOVA no ha corregido nada a propósito: un saldo fabricado es peor que '
                .'un saldo incorrecto conocido. Ejecuta `php artisan mova:reconcile-ledger --json` '
                .'para ver el detalle por lección y por profesor.',
            context: [
                'Lecciones examinadas' => $report['total_lessons'],
                'Lecciones anómalas' => count($report['anomalies']),
                'Profesores descuadrados' => count($report['profile_mismatches']),
            ],
            severity: OperationalAlert::SEVERITY_CRITICAL,
        );
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
