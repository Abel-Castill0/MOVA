<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Reconciliación contable de MOVA, en SOLO LECTURA.
 *
 * Lógica compartida por el comando `mova:reconcile-ledger` y por el trait de
 * invariantes de los tests, para que producción y suite midan exactamente lo
 * mismo. Nunca escribe: sin UPDATE, INSERT, DELETE ni transacciones.
 *
 * REGLA CENTRAL: la reconciliación es POR LESSON, nunca agregada por profesor.
 * Durante la Fase 3A se comprobó que la fórmula agregada
 * `SUM(reservation) - SUM(consumption) - SUM(refund)` produce FALSOS
 * DESCUADRES en cuanto existe un solo consumo huérfano, porque resta cierres
 * contra reservas de lecciones distintas.
 */
class LedgerReconciliation
{
    public const HEALTHY_CONSUMED = 'HEALTHY_CONSUMED';

    public const HEALTHY_REFUNDED = 'HEALTHY_REFUNDED';

    public const OPEN_RESERVATION = 'OPEN_RESERVATION';

    public const NO_LEDGER = 'NO_LEDGER';

    public const INVALID = 'INVALID';

    /**
     * Estados en los que una clase legítimamente puede no tener ningún
     * movimiento en el ledger.
     *
     * Hoy el conjunto está VACÍO, y es deliberado: la reserva se crea en
     * LessonController::store() dentro de la misma transacción que la propia
     * lección, así que toda clase existente debería tener su asiento. Un
     * NO_LEDGER es por tanto siempre sospechoso — pero se clasifica aparte de
     * INVALID en vez de fundirse con él, porque su causa y su remediación son
     * distintas: falta el asiento entero, no hay una combinación imposible.
     */
    public const STATES_ALLOWED_WITHOUT_LEDGER = [];

    /** @return array<string,mixed> */
    public function run(): array
    {
        $lessons = $this->classifyLessons();
        $profiles = $this->reconcileProfiles();

        $counts = [
            self::HEALTHY_CONSUMED => 0,
            self::HEALTHY_REFUNDED => 0,
            self::OPEN_RESERVATION => 0,
            self::NO_LEDGER => 0,
            self::INVALID => 0,
        ];

        foreach ($lessons as $lesson) {
            $counts[$lesson['classification']]++;
        }

        $anomalies = array_values(array_filter(
            $lessons,
            fn ($l) => in_array($l['classification'], [self::INVALID, self::NO_LEDGER], true)
                && ! in_array($l['status'], self::STATES_ALLOWED_WITHOUT_LEDGER, true)
        ));

        $profileMismatches = array_values(array_filter(
            $profiles,
            fn ($p) => ! $p['reserved_ok'] || ! $p['available_ok']
        ));

        return [
            'total_lessons' => count($lessons),
            'counts' => $counts,
            'lessons' => $lessons,
            'anomalies' => $anomalies,
            'profiles' => $profiles,
            'profile_mismatches' => $profileMismatches,
            'healthy' => count($anomalies) === 0 && count($profileMismatches) === 0,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function classifyLessons(): array
    {
        $out = [];

        foreach (DB::table('classes')->orderBy('id')->get() as $lesson) {
            $transactions = DB::table('credit_transactions')->where('lesson_id', $lesson->id)->get();

            $reservations = $transactions->where('type', 'reservation');
            $reservationCount = $reservations->count();
            $consumptionCount = $transactions->where('type', 'consumption')->count();
            $refundCount = $transactions->where('type', 'refund')->count();

            $classification = match (true) {
                $reservationCount === 1 && $consumptionCount === 1 && $refundCount === 0 => self::HEALTHY_CONSUMED,
                $reservationCount === 1 && $consumptionCount === 0 && $refundCount === 1 => self::HEALTHY_REFUNDED,
                $reservationCount === 1 && $consumptionCount === 0 && $refundCount === 0 => self::OPEN_RESERVATION,
                $reservationCount === 0 && $consumptionCount === 0 && $refundCount === 0 => self::NO_LEDGER,
                default => self::INVALID,
            };

            $out[] = [
                'lesson_id' => $lesson->id,
                'teacher_profile_id' => $lesson->teacher_profile_id,
                'status' => $lesson->status,
                'credits_settled_at' => $lesson->credits_settled_at ?? null,
                'reservations' => $reservationCount,
                'consumptions' => $consumptionCount,
                'refunds' => $refundCount,
                'reserved_amount' => (int) ($reservations->first()->amount ?? 0),
                'transaction_ids' => $transactions->pluck('id')->all(),
                'classification' => $classification,
                'reason' => $this->explain($classification, $lesson->status, $reservationCount, $consumptionCount, $refundCount),
            ];
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public function reconcileProfiles(): array
    {
        $out = [];

        foreach (DB::table('teacher_profiles')->orderBy('id')->get() as $profile) {
            $out[] = [
                'teacher_profile_id' => $profile->id,
                'credits_reserved_stored' => (int) $profile->credits_reserved,
                'credits_reserved_derived' => $this->openReservedFor($profile->id),
                'reserved_ok' => (int) $profile->credits_reserved === $this->openReservedFor($profile->id),
                'credits_available_stored' => (int) $profile->credits_available,
                'credits_available_derived' => $this->derivedAvailableFor($profile->id),
                'available_ok' => (int) $profile->credits_available === $this->derivedAvailableFor($profile->id),
            ];
        }

        return $out;
    }

    /**
     * Reservas abiertas de un profesor: asientos `reservation` cuya lección
     * todavía NO tiene asiento de cierre. Relación por lección, nunca suma
     * agregada por tipo.
     */
    public function openReservedFor(int $teacherProfileId): int
    {
        return (int) DB::table('credit_transactions as r')
            ->where('r.type', 'reservation')
            ->where('r.teacher_profile_id', $teacherProfileId)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('credit_transactions as c')
                    ->whereColumn('c.lesson_id', 'r.lesson_id')
                    ->whereIn('c.type', ['consumption', 'refund']);
            })
            ->sum('r.amount');
    }

    /**
     * `consumption` NO entra en la fórmula: descuenta de credits_reserved, no
     * de credits_available (ver TeacherReviewController::store()).
     */
    public function derivedAvailableFor(int $teacherProfileId): int
    {
        $sum = fn (string $type) => (int) DB::table('credit_transactions')
            ->where('teacher_profile_id', $teacherProfileId)
            ->where('type', $type)
            ->sum('amount');

        // Bug real encontrado auditando Mercado Pago (2026-08-29): 'reversal'
        // faltaba aquí desde que el tipo existe (2026_08_23_000001_
        // add_reversal_type_to_credit_transactions.php) — esa misma migración
        // ya advertía que 'reversal' necesitaba amount NEGATIVO precisamente
        // para poder sumarse aquí igual que los demás tipos. $sum('reversal')
        // ya trae el signo correcto (RechargeApprovalService::reverse()
        // siempre escribe amount negativo), así que se suma, no se resta.
        return $sum('deposit') - $sum('reservation') + $sum('refund') + $sum('reversal');
    }

    private function explain(string $classification, string $status, int $reservations, int $consumptions, int $refunds): string
    {
        return match ($classification) {
            self::HEALTHY_CONSUMED => 'Reserva cerrada por consumo.',
            self::HEALTHY_REFUNDED => 'Reserva cerrada por devolución.',
            self::OPEN_RESERVATION => 'Reserva abierta: cuenta hacia credits_reserved.',
            self::NO_LEDGER => in_array($status, self::STATES_ALLOWED_WITHOUT_LEDGER, true)
                ? "Sin ledger, legítimo para el estado '{$status}'."
                : "Sin ningún asiento. Toda clase debería tener reserva (creada en store()); revisar el origen de esta lección en estado '{$status}'.",
            default => "Combinación imposible: {$reservations} reserva(s), {$consumptions} consumo(s), {$refunds} devolución(es).",
        };
    }
}
