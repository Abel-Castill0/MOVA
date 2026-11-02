<?php

namespace App\Console\Commands;

use App\Models\ClassRequest;
use App\Models\CreditTransaction;
use App\Models\Lesson;
use App\Models\RechargeRequest;
use App\Support\LedgerReconciliation;
use App\Support\QaDatabaseGuard;
use Illuminate\Console\Command;

/**
 * GAP-03 — Verifica el estado final tras una tanda de procesos simultáneos.
 *
 * El criterio es siempre el mismo: por muchos procesos que hayan competido,
 * debe quedar EXACTAMENTE UNA mutación de estado y el ledger debe seguir
 * cuadrando. Exit code 1 si no.
 */
class ConcurrencyVerify extends Command
{
    protected $signature = 'mova:concurrency-verify {operation} {scenario} {--connection= : obligatorio mysql_qa (base fija mova_qa); no se admite la conexión por defecto}';

    protected $description = 'GAP-03: comprueba que N procesos simultáneos produjeron exactamente una mutación';

    public function handle(): int
    {
        // La verificación también debe usar exclusivamente el esquema QA.
        QaDatabaseGuard::assertSafeEnvironment();

        if ($this->option('connection') !== 'mysql_qa') {
            throw new \RuntimeException('MOVA QA GUARD: mova:concurrency-verify exige --connection=mysql_qa. Abortado sin tocar datos.');
        }
        QaDatabaseGuard::assertLoopbackConnection('mysql_qa');
        QaDatabaseGuard::assertDatabase('mysql_qa', 'mova_qa');
        config(['database.default' => 'mysql_qa']);

        $operation = $this->argument('operation');
        $id = (int) $this->argument('scenario');

        $ok = match ($operation) {
            'accept-lesson' => $this->verifyAcceptLesson($id),
            'settle' => $this->verifyLedgerEntry($id, 'consumption', 'completed'),
            'refund' => $this->verifyLedgerEntry($id, 'refund', 'cancelled'),
            'approve-recharge' => $this->verifyRecharge($id),
            'reminder-claim' => $this->verifyReminderClaim($id),
            default => false,
        };

        // Una anomalía antigua de OTRO perfil no puede volver roja esta
        // carrera. Solo se comprueba el ledger del perfil del escenario.
        $profileId = match ($operation) {
            'accept-lesson' => ClassRequest::find($id)?->subject?->teachers()->value('teacher_profiles.id'),
            'approve-recharge' => RechargeRequest::whereKey($id)->value('teacher_profile_id'),
            'settle', 'refund', 'reminder-claim' => Lesson::whereKey($id)->value('teacher_profile_id'),
            default => null,
        };
        $report = app(LedgerReconciliation::class)->run();
        $ledgerOk = $profileId !== null
            && ! collect($report['anomalies'])->contains(fn ($row) => (int) $row['teacher_profile_id'] === (int) $profileId)
            && ! collect($report['profile_mismatches'])->contains(fn ($row) => (int) $row['teacher_profile_id'] === (int) $profileId);

        $this->line('ledger del escenario sano: '.($ledgerOk ? 'SI' : 'NO'));

        if ($ok && $ledgerOk) {
            $this->info('GREEN — exactamente una mutación y ledger consistente.');

            return self::SUCCESS;
        }

        $this->error('ROJO — la concurrencia produjo un estado inesperado.');

        return self::FAILURE;
    }

    private function verifyAcceptLesson(int $requestId): bool
    {
        $lesson = Lesson::where('class_request_id', $requestId)->first();
        $lessons = Lesson::where('class_request_id', $requestId)->count();
        $reservations = CreditTransaction::whereIn('lesson_id', Lesson::where('class_request_id', $requestId)->pluck('id'))
            ->where('type', 'reservation')->count();
        $request = ClassRequest::find($requestId);
        $status = $request?->status;
        $expectedTeacher = $request?->subject?->teachers()->value('teacher_profiles.id');

        $this->line("lecciones creadas: {$lessons} (esperado 1)");
        $this->line("reservas en ledger: {$reservations} (esperado 1)");
        $this->line("estado de la solicitud: {$status} (esperado accepted)");
        $this->line('docente del escenario: '.($lesson?->teacher_profile_id === $expectedTeacher ? 'SI' : 'NO'));

        return $lessons === 1 && $reservations === 1 && $status === 'accepted'
            && $lesson?->teacher_profile_id !== null
            && (int) $lesson->teacher_profile_id === (int) $expectedTeacher;
    }

    private function verifyLedgerEntry(int $lessonId, string $type, string $expectedStatus): bool
    {
        $count = CreditTransaction::where('lesson_id', $lessonId)->where('type', $type)->count();
        $lesson = Lesson::find($lessonId);
        $this->line("asientos '{$type}': {$count} (esperado 1)");
        $this->line('estado terminal: '.($lesson?->status ?? 'inexistente')." (esperado {$expectedStatus})");
        $this->line('liquidación registrada: '.($lesson?->credits_settled_at ? 'SI' : 'NO'));

        return $count === 1 && $lesson?->status === $expectedStatus && $lesson?->credits_settled_at !== null;
    }

    private function verifyRecharge(int $rechargeId): bool
    {
        $deposits = CreditTransaction::where('recharge_request_id', $rechargeId)->where('type', 'deposit')->count();
        $recharge = RechargeRequest::find($rechargeId);
        $credits = $recharge?->teacherProfile?->credits_available;

        $this->line("depósitos: {$deposits} (esperado 1)");
        $this->line("créditos abonados: {$credits} (esperado 5)");

        return $deposits === 1 && $recharge?->status === 'approved' && (int) $credits === 5;
    }

    private function verifyReminderClaim(int $lessonId): bool
    {
        $lesson = Lesson::find($lessonId);
        $claimed = $lesson?->reminder_24h_sent_at !== null;

        $this->line('marcador reclamado: '.($claimed ? 'SI' : 'NO').' (esperado SI, y solo un proceso debió ganar)');

        return $claimed;
    }
}
