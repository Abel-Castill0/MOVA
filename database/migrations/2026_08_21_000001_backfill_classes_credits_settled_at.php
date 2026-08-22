<?php

use App\Support\LedgerReconciliation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * C-1 Entrega 2 — Fase 1: backfill de credits_settled_at.
 *
 * Deriva la columna EXCLUSIVAMENTE de CreditTransaction (la autoridad
 * financiera real, ver Fase 3B §2). Nunca infiere desde updated_at ni desde
 * el status por sí solo — ambos fueron descartados explícitamente durante el
 * diseño porque no demuestran que existió un asiento de cierre.
 *
 * Reutiliza LedgerReconciliation (la misma clase que usa
 * `mova:reconcile-ledger` y el trait de invariantes de los tests) para que
 * el backfill, el comando y la suite clasifiquen exactamente igual — evita
 * la clase de bug que ya encontramos con hasScheduleOverlap() duplicado
 * en C-2.
 *
 * Todo dentro de una única transacción: si el post-check detecta un
 * descuadre, la excepción revierte el backfill completo, no lo deja a
 * medias.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $this->assertNoInvalidLessonsBeforeBackfill();

            $classified = (new LedgerReconciliation)->classifyLessons();
            $settled = 0;
            $skipped = 0;

            foreach ($classified as $lesson) {
                $settledAt = match ($lesson['classification']) {
                    LedgerReconciliation::HEALTHY_CONSUMED => $this->closureTimestamp($lesson['lesson_id'], 'consumption'),
                    LedgerReconciliation::HEALTHY_REFUNDED => $this->closureTimestamp($lesson['lesson_id'], 'refund'),
                    default => null, // OPEN_RESERVATION / NO_LEDGER permitido: se deja NULL, sigue abierta.
                };

                if ($settledAt === null) {
                    $skipped++;

                    continue;
                }

                // status debe ser coherente con el tipo de cierre: una lección
                // HEALTHY_CONSUMED que no está en 'completed', o HEALTHY_REFUNDED
                // que no está en 'cancelled', es una discrepancia status↔ledger
                // que el backfill NO debe silenciar escribiendo igual.
                $expectedStatus = $lesson['classification'] === LedgerReconciliation::HEALTHY_CONSUMED
                    ? 'completed'
                    : 'cancelled';

                if ($lesson['status'] !== $expectedStatus) {
                    throw new RuntimeException(
                        "Backfill abortado: Lesson {$lesson['lesson_id']} está clasificada como "
                        ."{$lesson['classification']} (esperaría status='{$expectedStatus}') pero su "
                        ."status real es '{$lesson['status']}'. Discrepancia status↔ledger: requiere "
                        .'remediación aprobada, no debe backfillearse a ciegas.'
                    );
                }

                DB::table('classes')->where('id', $lesson['lesson_id'])->update([
                    'credits_settled_at' => $settledAt,
                ]);
                $settled++;
            }

            echo "  Backfill: {$settled} lección(es) liquidadas retroactivamente, {$skipped} sin cerrar (se dejaron NULL).".PHP_EOL;

            $this->assertBalancesStillReconcileAfterBackfill();
        });
    }

    public function down(): void
    {
        $this->assertRollbackDoesNotDiscardBackfilledHistory();

        DB::table('classes')->whereNotNull('credits_settled_at')->update(['credits_settled_at' => null]);
    }

    /**
     * Pre-check: ninguna lección puede tener una combinación imposible en el
     * ledger antes de empezar. El backfill descubre anomalías, nunca las
     * fabrica ni las oculta escribiendo encima.
     */
    private function assertNoInvalidLessonsBeforeBackfill(): void
    {
        $invalid = (new LedgerReconciliation)->classifyLessons();
        $invalid = array_filter($invalid, fn ($l) => $l['classification'] === LedgerReconciliation::INVALID);

        if ($invalid !== []) {
            $ids = implode(', ', array_column($invalid, 'lesson_id'));

            throw new RuntimeException(
                "Backfill abortado antes de escribir nada: lección(es) INVALID detectada(s): {$ids}. "
                .'Requieren remediación aprobada — ejecuta `php artisan mova:reconcile-ledger` para el detalle.'
            );
        }
    }

    /**
     * Post-check: tras escribir credits_settled_at, TODO el ledger —no solo
     * las filas tocadas— debe seguir reconciliando. Reutiliza la misma
     * verificación que el gate duro de la Entrega 1.
     */
    private function assertBalancesStillReconcileAfterBackfill(): void
    {
        $report = (new LedgerReconciliation)->run();

        if (! $report['healthy']) {
            throw new RuntimeException(
                'Backfill abortado: el ledger dejó de reconciliar después de escribir credits_settled_at. '
                .'Se revierte la transacción completa. Ejecuta `php artisan mova:reconcile-ledger` para el detalle.'
            );
        }
    }

    private function closureTimestamp(int $lessonId, string $type): ?string
    {
        return DB::table('credit_transactions')
            ->where('lesson_id', $lessonId)
            ->where('type', $type)
            ->value('created_at');
    }

    /**
     * Mismo criterio que las 3 migraciones de la Entrega 1: un down()
     * financiero nunca destruye evidencia en silencio. Si alguna liquidación
     * automática (Fase 3+) ya escribió sobre este backfill, revertir a NULL
     * borraría cuándo se liquidó de verdad — eso requiere remediación
     * explícita, no un rollback automático.
     */
    private function assertRollbackDoesNotDiscardBackfilledHistory(): void
    {
        $settled = DB::table('classes')->whereNotNull('credits_settled_at')->count();

        if ($settled > 0) {
            throw new RuntimeException(
                "Rollback abortado: {$settled} clase(s) tienen credits_settled_at. Revertir el backfill "
                .'destruiría el vínculo entre esas clases y el momento real de su liquidación.'
            );
        }
    }
};
