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
    protected $signature = 'mova:concurrency-verify {operation} {scenario} {--connection= : nombre de conexión de base de datos a usar en vez de la de por defecto (MOVA MYSQL QA GATE: solo se acepta si resuelve exactamente a la base de datos mova_qa; falla cerrado en cualquier otro caso)}';

    protected $description = 'GAP-03: comprueba que N procesos simultáneos produjeron exactamente una mutación';

    public function handle(): int
    {
        // MOVA MYSQL QA GATE: incondicional, ANTES de la rama --connection —
        // mismo motivo que ConcurrencyProbe. Sin esto, omitir --connection
        // dejaba este comando corriendo (incluida la escritura de
        // LedgerReconciliation) contra la conexión por defecto sin ningún
        // guard, sin importar el entorno. El uso histórico en local/testing
        // sigue igual.
        QaDatabaseGuard::assertSafeEnvironment();

        if ($connection = $this->option('connection')) {
            config(['database.default' => $connection]);
            QaDatabaseGuard::assertDatabase($connection, 'mova_qa');
        }

        $operation = $this->argument('operation');
        $id = (int) $this->argument('scenario');

        $ok = match ($operation) {
            'accept-lesson' => $this->verifyAcceptLesson($id),
            'settle' => $this->verifyLedgerEntry($id, 'consumption'),
            'refund' => $this->verifyLedgerEntry($id, 'refund'),
            'approve-recharge' => $this->verifyRecharge($id),
            'reminder-claim' => $this->verifyReminderClaim($id),
            default => false,
        };

        $report = app(LedgerReconciliation::class)->run();
        $ledgerOk = $report['healthy'] ?? false;

        $this->line('ledger sano: '.($ledgerOk ? 'SI' : 'NO'));

        if ($ok && $ledgerOk) {
            $this->info('GREEN — exactamente una mutación y ledger consistente.');

            return self::SUCCESS;
        }

        $this->error('ROJO — la concurrencia produjo un estado inesperado.');

        return self::FAILURE;
    }

    private function verifyAcceptLesson(int $requestId): bool
    {
        $lessons = Lesson::where('class_request_id', $requestId)->count();
        $reservations = CreditTransaction::whereIn('lesson_id', Lesson::where('class_request_id', $requestId)->pluck('id'))
            ->where('type', 'reservation')->count();
        $status = ClassRequest::find($requestId)?->status;

        $this->line("lecciones creadas: {$lessons} (esperado 1)");
        $this->line("reservas en ledger: {$reservations} (esperado 1)");
        $this->line("estado de la solicitud: {$status} (esperado accepted)");

        return $lessons === 1 && $reservations === 1 && $status === 'accepted';
    }

    private function verifyLedgerEntry(int $lessonId, string $type): bool
    {
        $count = CreditTransaction::where('lesson_id', $lessonId)->where('type', $type)->count();
        $this->line("asientos '{$type}': {$count} (esperado 1)");

        return $count === 1;
    }

    private function verifyRecharge(int $rechargeId): bool
    {
        $deposits = CreditTransaction::where('recharge_request_id', $rechargeId)->where('type', 'deposit')->count();
        $recharge = RechargeRequest::find($rechargeId);
        $credits = $recharge?->teacherProfile?->credits_available;

        $this->line("depósitos: {$deposits} (esperado 1)");
        $this->line("créditos abonados: {$credits} (esperado 5)");

        return $deposits === 1 && (int) $credits === 5;
    }

    private function verifyReminderClaim(int $lessonId): bool
    {
        $lesson = Lesson::find($lessonId);
        $claimed = $lesson?->reminder_24h_sent_at !== null;

        $this->line('marcador reclamado: '.($claimed ? 'SI' : 'NO').' (esperado SI, y solo un proceso debió ganar)');

        return $claimed;
    }
}
