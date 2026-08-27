<?php

namespace App\Console\Commands;

use App\Models\ClassEvent;
use App\Models\CreditTransaction;
use App\Models\Lesson;
use App\Services\LessonSettlementService;
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

    /** @return array{candidates: int, succeeded: int, failed: int, anomalies: array} */
    private function settleGraceExpired(LessonSettlementService $settlement, bool $dryRun, bool $json): array
    {
        $graceDays = (int) config('credits.settlement_grace_days', 7);
        $cutoff = now()->subDays($graceDays);

        // Solo IDs: cada lección se vuelve a leer (y bloquear) individualmente
        // dentro de LessonSettlementService::consume(), nunca desde esta lista.
        $ids = Lesson::whereIn('status', ['paid', 'pending_parent_confirmation'])
            ->whereNull('credits_settled_at')
            ->endedBefore($cutoff)
            ->orderBy('id')
            ->pluck('id');

        if (!$json) {
            $this->info("Consumo automático (gracia: {$graceDays}d, corte: {$cutoff->toDateTimeString()}) — candidatas: {$ids->count()}");
        }

        // Preflight: cuántas reservas de ledger respaldan a cada candidata,
        // ANTES de intentar liquidar nada. Exactamente 1 es el único caso sano.
        $anomalies = [];
        foreach ($ids as $id) {
            $reservations = CreditTransaction::where('lesson_id', $id)->where('type', 'reservation')->count();
            if ($reservations !== 1) {
                $anomalies[] = ['lesson_id' => $id, 'reservations_found' => $reservations];
            }
        }

        $settled = 0;
        $failed = 0;

        foreach ($ids as $id) {
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

        if (!$json) {
            $this->info(($dryRun ? '[dry-run] ' : '')."Consumo automático: {$ids->count()} candidata(s), {$settled} liquidada(s), {$failed} error(es), ".count($anomalies).' anomalía(s).');
        }

        return ['candidates' => $ids->count(), 'succeeded' => $settled, 'failed' => $failed, 'anomalies' => $anomalies];
    }

    /** @return array{candidates: int, succeeded: int, failed: int} */
    private function escalateUnconfirmed(bool $dryRun, bool $json): array
    {
        $unconfirmedDays = (int) config('credits.unconfirmed_days', 7);
        $cutoff = now()->subDays($unconfirmedDays);

        $ids = Lesson::where('status', 'scheduled')
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

            try {
                DB::transaction(function () use ($id, $unconfirmedDays) {
                    $lesson = Lesson::whereKey($id)->lockForUpdate()->firstOrFail();

                    // Releído bajo lock: si otro proceso ya la movió (cancel,
                    // reschedule, u otra pasada concurrente), no hay nada que
                    // escalar — no es un error, es la carrera resuelta.
                    if ($lesson->status !== 'scheduled') {
                        return;
                    }

                    $lesson->update(['status' => 'needs_admin_review']);

                    ClassEvent::log(
                        'class_needs_review',
                        null,
                        $lesson->id,
                        $lesson->class_request_id,
                        "Sin confirmar {$unconfirmedDays} días tras el fin de la clase"
                    );
                });
                $escalated++;
                if (!$json) {
                    $this->line("  Lesson {$id}: escalada a needs_admin_review.");
                }
            } catch (\Throwable $e) {
                $failed++;
                if (!$json) {
                    $this->error("  Lesson {$id}: {$e->getMessage()}");
                }
                report($e);
            }
        }

        if (!$json) {
            $this->info(($dryRun ? '[dry-run] ' : '')."Escalado a revisión: {$escalated} clase(s), {$failed} error(es).");
        }

        return ['candidates' => $ids->count(), 'succeeded' => $escalated, 'failed' => $failed];
    }
}
