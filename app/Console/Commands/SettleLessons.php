<?php

namespace App\Console\Commands;

use App\Models\ClassEvent;
use App\Models\Lesson;
use App\Services\LessonSettlementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
 * eso el Kernel agenda este comando CON --dry-run por defecto (ver Kernel.php).
 * Quitar esa bandera para activar la liquidación real es una decisión de
 * negocio explícita, no un detalle de despliegue.
 */
class SettleLessons extends Command
{
    protected $signature = 'mova:settle-lessons {--dry-run : Muestra qué liquidaría/escalaría sin escribir nada}';

    protected $description = 'Liquida automáticamente clases paid/pending_parent_confirmation vencidas y escala scheduled sin confirmar';

    public function handle(LessonSettlementService $settlement): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('--dry-run: no se escribirá nada.');
        }

        $this->settleGraceExpired($settlement, $dryRun);
        $this->newLine();
        $this->escalateUnconfirmed($dryRun);

        return self::SUCCESS;
    }

    private function settleGraceExpired(LessonSettlementService $settlement, bool $dryRun): void
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

        $this->info("Consumo automático (gracia: {$graceDays}d, corte: {$cutoff->toDateTimeString()}) — candidatas: {$ids->count()}");

        $settled = 0;
        $failed = 0;

        foreach ($ids as $id) {
            if ($dryRun) {
                $this->line("  [dry-run] liquidaría Lesson {$id}");
                $settled++;

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
                $this->line("  Lesson {$id}: liquidada.");
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  Lesson {$id}: {$e->getMessage()}");
                report($e);
            }
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."Consumo automático: {$settled} liquidada(s), {$failed} error(es).");
    }

    private function escalateUnconfirmed(bool $dryRun): void
    {
        $unconfirmedDays = (int) config('credits.unconfirmed_days', 7);
        $cutoff = now()->subDays($unconfirmedDays);

        $ids = Lesson::where('status', 'scheduled')
            ->endedBefore($cutoff)
            ->orderBy('id')
            ->pluck('id');

        $this->info("Escalado a revisión (plazo: {$unconfirmedDays}d, corte: {$cutoff->toDateTimeString()}) — candidatas: {$ids->count()}");

        $escalated = 0;
        $failed = 0;

        foreach ($ids as $id) {
            if ($dryRun) {
                $this->line("  [dry-run] escalaría Lesson {$id} a needs_admin_review");
                $escalated++;

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
                $this->line("  Lesson {$id}: escalada a needs_admin_review.");
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  Lesson {$id}: {$e->getMessage()}");
                report($e);
            }
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."Escalado a revisión: {$escalated} clase(s), {$failed} error(es).");
    }
}
