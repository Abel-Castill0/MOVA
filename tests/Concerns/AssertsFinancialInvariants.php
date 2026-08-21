<?php

namespace Tests\Concerns;

use App\Support\LedgerReconciliation;
use Illuminate\Support\Facades\DB;

/**
 * Invariantes contables que deben cumplirse SIEMPRE, en cualquier escenario.
 *
 * Pensado para llamarse al final de cada test financiero, no como un test
 * aislado: es la aserción que habría detectado C-1 (créditos atrapados) sin
 * necesidad de una auditoría manual.
 *
 * Comparte `LedgerReconciliation` con el comando `mova:reconcile-ledger`, para
 * que la suite y producción midan exactamente lo mismo.
 */
trait AssertsFinancialInvariants
{
    protected function assertFinancialInvariantsHold(): void
    {
        $this->assertLessonLedgerInvariants();
        $this->assertTeacherBalanceInvariants();
    }

    /**
     * Por lección: como máximo un asiento de cada tipo, nunca consumo y
     * devolución a la vez, y ningún cierre sin su reserva previa.
     */
    protected function assertLessonLedgerInvariants(): void
    {
        foreach ((new LedgerReconciliation)->classifyLessons() as $lesson) {
            $id = $lesson['lesson_id'];

            $this->assertLessThanOrEqual(1, $lesson['reservations'], "Lesson {$id}: más de una reserva.");
            $this->assertLessThanOrEqual(1, $lesson['consumptions'], "Lesson {$id}: más de un consumo.");
            $this->assertLessThanOrEqual(1, $lesson['refunds'], "Lesson {$id}: más de una devolución.");

            $this->assertFalse(
                $lesson['consumptions'] > 0 && $lesson['refunds'] > 0,
                "Lesson {$id}: tiene consumo Y devolución; una clase no puede cerrarse dos veces."
            );

            $hasClosure = $lesson['consumptions'] > 0 || $lesson['refunds'] > 0;

            $this->assertFalse(
                $hasClosure && $lesson['reservations'] === 0,
                "Lesson {$id}: cierre sin reserva previa; no se puede liberar lo que nunca se reservó."
            );

            $this->assertNotSame(
                LedgerReconciliation::INVALID,
                $lesson['classification'],
                "Lesson {$id}: {$lesson['reason']}"
            );
        }
    }

    /**
     * Por profesor: saldos no negativos y `credits_reserved` explicado por las
     * reservas abiertas, calculadas POR LECCIÓN.
     *
     * Deliberadamente NO se usa `SUM(reservation) - SUM(consumption) -
     * SUM(refund)`: esa fórmula agregada produce falsos descuadres en cuanto
     * existe un cierre huérfano, porque resta contra reservas de lecciones
     * distintas.
     */
    protected function assertTeacherBalanceInvariants(): void
    {
        $reconciliation = new LedgerReconciliation;

        foreach (DB::table('teacher_profiles')->orderBy('id')->get() as $profile) {
            $this->assertGreaterThanOrEqual(
                0,
                (int) $profile->credits_available,
                "TeacherProfile {$profile->id}: credits_available negativo."
            );

            $this->assertGreaterThanOrEqual(
                0,
                (int) $profile->credits_reserved,
                "TeacherProfile {$profile->id}: credits_reserved negativo."
            );

            $this->assertSame(
                $reconciliation->openReservedFor($profile->id),
                (int) $profile->credits_reserved,
                "TeacherProfile {$profile->id}: credits_reserved no coincide con las reservas abiertas del ledger."
            );
        }
    }

    /**
     * Entrega 1: la columna existe y es nullable, pero todavía NO hay
     * comportamiento de settlement ni backfill.
     *
     * La equivalencia final `credits_settled_at IS NOT NULL ⟺ existe cierre`
     * NO se afirma aquí a propósito: hasta que el backfill se ejecute, una
     * lección históricamente cerrada tiene la columna a NULL, y eso es el
     * estado esperado. Afirmarla ahora sería codificar en un test una
     * expectativa que el sistema todavía no cumple.
     */
    protected function assertSettlementBehaviourNotYetIntroduced(): void
    {
        $this->assertSame(
            0,
            DB::table('classes')->whereNotNull('credits_settled_at')->count(),
            'Alguna clase tiene credits_settled_at, pero la capa de settlement aún no existe (Entrega 1).'
        );
    }
}
