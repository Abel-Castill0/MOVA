<?php

namespace App\Console\Commands;

use App\Models\ClassEvent;
use App\Models\CreditTransaction;
use App\Models\Lesson;
use App\Models\OperationalAlert;
use App\Services\LessonSettlementService;
use App\Services\OperationalAlertService;
use App\Support\LedgerReconciliation;
use Illuminate\Support\Facades\DB;
use Illuminate\Console\Command;

/**
 * C-1 — automatiza lo que hoy depende de que el padre escriba una reseña
 * voluntaria. Dos barridos independientes, cada uno en su propia transacción
 * POR LECCIÓN (nunca por lote — ver Fase 3B §6 y §12: es lo que impide que
 * este comando participe en el deadlock multi-lección de C-2):
 *
 *   1) Consumo automático: 'paid' / 'pending_parent_confirmation' sin cerrar,
 *      terminadas hace más de `settlement_grace_days` -> LessonSettlementService::consume().
 *   2) Escalado: 'scheduled' terminadas hace más de `unconfirmed_days` sin que
 *      nadie confirmara nada -> 'needs_admin_review'. SIN efecto financiero —
 *      el crédito sigue reservado hasta que un admin decida (force-complete o
 *      force-refund).
 *
 * --dry-run: NO escribe nada, solo reporta qué haría. La primera ejecución
 * real siempre debe ir precedida de una revisión humana del dry-run — por
 * eso el Kernel agenda este comando CON --dry-run por defecto (ver Kernel.php
 * / App\Support\SettlementMode). Quitar esa bandera para activar la
 * liquidación real es una decisión de negocio explícita, no un detalle de
 * despliegue.
 *
 * --json: salida estructurada para que esa revisión humana (o un pipeline de
 * despliegue) no dependa de parsear texto de consola. Incluye una detección
 * de anomalías POR ADELANTADO — antes de que una ejecución real tropezara con
 * ellas — para que "SAFE TO ENABLE" sea una conclusión verificable, no una
 * lectura optimista de "candidatas: N".
 */
class SettleLessons extends Command
{
    protected $signature = 'mova:settle-lessons {--dry-run : Muestra qué liquidaría/escalaría sin escribir nada} {--json : Salida estructurada en JSON}';

    protected $description = 'Liquida automáticamente clases paid/pending_parent_confirmation vencidas y escala scheduled sin confirmar';

    public function handle(LessonSettlementService $settlement): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $json = (bool) $this->option('json');

        if ($dryRun && !$json) {
            $this->warn('--dry-run: no se escribirá nada.');
        }

        $consume = $this->settleGraceExpired($settlement, $dryRun, $json);
        if (!$json) {
            $this->newLine();
        }
        $review = $this->escalateUnconfirmed($dryRun, $json);

        if ($json) {
            $this->line((string) json_encode([
                'dry_run' => $dryRun,
                'would_consume' => $consume['candidates'],
                'consumed' => $dryRun ? 0 : $consume['succeeded'],
                'consume_errors' => $consume['failed'],
                // BUG-3: candidatas 'paid'/'pending_parent_confirmation' sin
                // reporte pedagógico — se escalan a needs_admin_review en vez
                // de auto-completarse. Contador separado de 'would_consume'
                // a propósito, para que un dry-run muestre la distinción.
                'would_escalate_missing_report' => $consume['missing_report_candidates'],
                'escalated_missing_report' => $dryRun ? 0 : $consume['missing_report_succeeded'],
                'would_review' => $review['candidates'],
                'escalated_to_review' => $dryRun ? 0 : $review['succeeded'],
                'review_errors' => $review['failed'],
                // Anomalías detectadas ANTES de tocar nada: lecciones candidatas
                // cuyo ledger no tiene exactamente 1 reserva. En una ejecución
                // real, LessonSettlementService las rechazaría con RuntimeException
                // (ver Lesson::reservedCreditAmount) en vez de fabricar un importe.
                'invalid' => $consume['anomalies'],
                'safe_to_enable' => $consume['anomalies'] === [] && $consume['failed'] === 0 && $review['failed'] === 0,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }

    /** @return array{candidates: int, succeeded: int, failed: int, anomalies: array, missing_report_candidates: int, missing_report_succeeded: int} */
    private function settleGraceExpired(LessonSettlementService $settlement, bool $dryRun, bool $json): array
    {
        $graceDays = (int) config('credits.settlement_grace_days', 7);
        $cutoff = now()->subDays($graceDays);

        // Solo IDs: cada lección se vuelve a leer (y bloquear) individualmente
        // dentro de LessonSettlementService::consume(), nunca desde esta lista.
        //
        // AZ-3F: excluye lecciones anteriores a LedgerReconciliation::LEDGER_EPOCH.
        // Una lección LEGACY_PRE_LEDGER nunca tuvo (ni pudo tener) un asiento de
        // reserva -- credit_transactions no existía todavía -- así que
        // liquidarla o escalarla fabricaría un movimiento financiero sobre una
        // clase que el propio ledger ya sabe tratar aparte. Se queda preservada
        // como histórica, sin tocar su status.
        $ids = Lesson::whereIn('status', ['paid', 'pending_parent_confirmation'])
            ->whereNull('credits_settled_at')
            ->where('created_at', '>=', LedgerReconciliation::LEDGER_EPOCH)
            ->endedBefore($cutoff)
            ->orderBy('id')
            ->pluck('id');

        // BUG-3: separar, ANTES de tocar nada, las candidatas que sí tienen
        // reporte (se liquidan normalmente) de las que no (se escalan a
        // needs_admin_review — el reporte pedagógico es la garantía central
        // del producto, no algo que una clase pueda saltarse solo porque
        // pasó el plazo de gracia).
        $withReport = [];
        $missingReport = [];
        foreach ($ids as $id) {
            if (Lesson::whereKey($id)->whereHas('lessonReport')->exists()) {
                $withReport[] = $id;
            } else {
                $missingReport[] = $id;
            }
        }

        if (!$json) {
            $this->info("Consumo automático (gracia: {$graceDays}d, corte: {$cutoff->toDateTimeString()}) — candidatas: {$ids->count()} (".count($withReport)." con reporte, ".count($missingReport)." sin reporte → se escalan)");
        }

        // Preflight: cuántas reservas de ledger respaldan a cada candidata con
        // reporte, ANTES de intentar liquidar nada. Exactamente 1 es el único
        // caso sano. Las que se escalan no tocan el ledger, así que no aplica.
        $anomalies = [];
        foreach ($withReport as $id) {
            $reservations = CreditTransaction::where('lesson_id', $id)->where('type', 'reservation')->count();
            if ($reservations !== 1) {
                $anomalies[] = ['lesson_id' => $id, 'reservations_found' => $reservations];
            }
        }

        $settled = 0;
        $failed = 0;

        foreach ($withReport as $id) {
            if ($dryRun) {
                if (!$json) {
                    $this->line("  [dry-run] liquidaría Lesson {$id}");
                }
                continue;
            }

            try {
                $settlement->consume(
                    lesson: Lesson::findOrFail($id),
                    actorId: null,
                    reason: "Liquidación automática: sin cerrar {$graceDays} días tras el fin de la clase",
                    eventType: 'auto_settled',
                    notify: true
                );
                $settled++;
                if (!$json) {
                    $this->line("  Lesson {$id}: liquidada.");
                }
            } catch (\Throwable $e) {
                $failed++;
                if (!$json) {
                    $this->error("  Lesson {$id}: {$e->getMessage()}");
                }
                report($e);
            }
        }

        $escalatedMissingReport = 0;
        foreach ($missingReport as $id) {
            if ($dryRun) {
                if (!$json) {
                    $this->line("  [dry-run] escalaría Lesson {$id} a needs_admin_review (sin reporte pedagógico)");
                }
                continue;
            }

            if ($this->escalateToReview($id, "Sin reporte pedagógico del profesor {$graceDays} días tras el fin de la clase") !== 'error') {
                $escalatedMissingReport++;
                if (!$json) {
                    $this->line("  Lesson {$id}: escalada a needs_admin_review (sin reporte pedagógico).");
                }
            }
        }

        if (!$json) {
            $this->info(($dryRun ? '[dry-run] ' : '')."Consumo automático: ".count($withReport)." con reporte ({$settled} liquidada(s), {$failed} error(es)), ".count($missingReport)." sin reporte ({$escalatedMissingReport} escalada(s)), ".count($anomalies).' anomalía(s).');
        }

        return [
            'candidates' => $ids->count(),
            'succeeded' => $settled,
            'failed' => $failed,
            'anomalies' => $anomalies,
            'missing_report_candidates' => count($missingReport),
            'missing_report_succeeded' => $escalatedMissingReport,
        ];
    }

    /**
     * Escala una lección a needs_admin_review de forma segura bajo lock,
     * releyendo el estado real antes de escribir — compartido entre el
     * escalado por falta de reporte (BUG-3) y el escalado por falta de
     * confirmación (comportamiento original).
     *
     * @return string 'escalated' (se movió), 'raced' (otro proceso ya la
     *                movió — no es un error), o 'error' (excepción real).
     */
    private function escalateToReview(int $id, string $reason): string
    {
        try {
            $outcome = DB::transaction(function () use ($id, $reason) {
                $lesson = Lesson::whereKey($id)->lockForUpdate()->firstOrFail();

                // Releído bajo lock: si otro proceso ya la movió, no hay nada
                // que escalar — no es un error, es la carrera resuelta.
                if (! in_array($lesson->status, ['scheduled', 'paid', 'pending_parent_confirmation'], true)) {
                    return 'raced';
                }

                $lesson->update(['status' => 'needs_admin_review']);

                ClassEvent::log('class_needs_review', null, $lesson->id, $lesson->class_request_id, $reason);

                return 'escalated';
            });

            // Una clase varada en needs_admin_review es dinero detenido: los
            // créditos del profesor siguen RESERVADOS hasta que un admin decida
            // (force-complete o force-refund). Antes esto no avisaba a nadie —
            // ni al padre, ni al profesor, ni al admin
            // (docs/MOVA_SYSTEM_MAP.md §19.3).
            //
            // Solo se alerta en 'escalated': en 'raced' la clase ya la movió
            // otro proceso, y quien la movió ya alertó.
            if ($outcome === 'escalated') {
                app(OperationalAlertService::class)->raise(
                    key: "lesson:{$id}:needs_admin_review",
                    type: OperationalAlert::TYPE_LESSON_NEEDS_REVIEW,
                    title: 'Clase varada a la espera de decisión del administrador',
                    message: 'Una clase se escaló automáticamente a needs_admin_review. Los créditos del '
                        .'profesor siguen reservados y no se liberarán ni se consumirán hasta que un '
                        .'administrador cierre la clase (forzar cierre) o la devuelva (forzar devolución).',
                    context: [
                        'Clase' => $id,
                        'Motivo' => $reason,
                    ],
                    severity: OperationalAlert::SEVERITY_WARNING,
                );
            }

            return $outcome;
        } catch (\Throwable $e) {
            $this->error("  Lesson {$id}: {$e->getMessage()}");
            report($e);

            return 'error';
        }
    }

    /** @return array{candidates: int, succeeded: int, failed: int} */
    private function escalateUnconfirmed(bool $dryRun, bool $json): array
    {
        $unconfirmedDays = (int) config('credits.unconfirmed_days', 7);
        $cutoff = now()->subDays($unconfirmedDays);

        // AZ-3F: mismo corte que settleGraceExpired() -- una lección legacy
        // 'scheduled' sin confirmar tampoco puede escalarse a needs_admin_review
        // (créditos reservados que nunca existieron); sigue siendo
        // LEGACY_PRE_LEDGER para el reconciler, sin cambiar su status.
        $ids = Lesson::where('status', 'scheduled')
            ->where('created_at', '>=', LedgerReconciliation::LEDGER_EPOCH)
            ->endedBefore($cutoff)
            ->orderBy('id')
            ->pluck('id');

        if (!$json) {
            $this->info("Escalado a revisión (plazo: {$unconfirmedDays}d, corte: {$cutoff->toDateTimeString()}) — candidatas: {$ids->count()}");
        }

        $escalated = 0;
        $failed = 0;

        foreach ($ids as $id) {
            if ($dryRun) {
                if (!$json) {
                    $this->line("  [dry-run] escalaría Lesson {$id} a needs_admin_review");
                }
                continue;
            }

            if ($this->escalateToReview($id, "Sin confirmar {$unconfirmedDays} días tras el fin de la clase") === 'error') {
                $failed++;
            } else {
                // 'escalated' o 'raced' (otro proceso ya la movió) cuentan
                // igual que en el comportamiento original: no es un error.
                $escalated++;
                if (!$json) {
                    $this->line("  Lesson {$id}: escalada a needs_admin_review.");
                }
            }
        }

        if (!$json) {
            $this->info(($dryRun ? '[dry-run] ' : '')."Escalado a revisión: {$escalated} clase(s), {$failed} error(es).");
        }

        return ['candidates' => $ids->count(), 'succeeded' => $escalated, 'failed' => $failed];
    }
}
