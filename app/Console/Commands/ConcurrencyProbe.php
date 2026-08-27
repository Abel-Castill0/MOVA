<?php

namespace App\Console\Commands;

use App\Models\ClassRequest;
use App\Models\CreditTransaction;
use App\Models\Lesson;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Services\LessonSettlementService;
use App\Services\RechargeApprovalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * GAP-03 — Sonda de concurrencia REAL.
 *
 * La suite de PHPUnit corre sobre SQLite en memoria, una conexión, un proceso:
 * puede probar SERIALIZACIÓN (dos intentos consecutivos) pero nunca dos
 * procesos compitiendo por el mismo lock del motor. Eso dejaba GAP-03 abierto
 * y no era honesto llamarlo "test de concurrencia".
 *
 * Este comando ejecuta UN intento de una operación financiera contra la base de
 * datos REAL. `scripts/concurrency-probe.sh` lanza N instancias en paralelo con
 * `&` y espera a todas; después se comprueba el estado final. Es concurrencia
 * de verdad: procesos separados, conexiones separadas, locks reales de MySQL.
 *
 * SOLO LECTURA sobre datos existentes: cada operación crea su propio escenario
 * aislado con --scenario y lo limpia con --cleanup. Nunca toca datos previos.
 */
class ConcurrencyProbe extends Command
{
    protected $signature = 'mova:concurrency-probe
        {operation : accept-lesson|settle|refund|approve-recharge|reminder-claim}
        {--scenario= : id del escenario compartido creado con --setup}
        {--setup : crea el escenario y devuelve su id}
        {--cleanup : elimina el escenario}
        {--worker=0 : identificador del proceso, solo para el log}';

    protected $description = 'GAP-03: ejecuta un intento de una operación financiera para pruebas de concurrencia real';

    public function handle(): int
    {
        $operation = $this->argument('operation');

        if ($this->option('setup')) {
            return $this->setupScenario($operation);
        }

        if ($this->option('cleanup')) {
            return $this->cleanupScenario();
        }

        $scenario = (int) $this->option('scenario');
        $worker = $this->option('worker');

        if (!$scenario) {
            $this->error('Falta --scenario');

            return self::FAILURE;
        }

        try {
            $result = match ($operation) {
                'accept-lesson' => $this->attemptAcceptLesson($scenario),
                'settle' => $this->attemptSettle($scenario),
                'refund' => $this->attemptRefund($scenario),
                'approve-recharge' => $this->attemptApproveRecharge($scenario),
                'reminder-claim' => $this->attemptReminderClaim($scenario),
            };

            $this->line("worker={$worker} result={$result}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            // Perder la carrera es un resultado esperado, no un error: se
            // reporta para que el runner pueda contar ganadores y perdedores.
            $this->line("worker={$worker} result=LOST reason=".str_replace("\n", ' ', substr($e->getMessage(), 0, 120)));

            return self::SUCCESS;
        }
    }

    // ── Escenarios ───────────────────────────────────────────────────────

    private function setupScenario(string $operation): int
    {
        $profile = TeacherProfile::create([
            'user_id' => \App\Models\User::factory()->create()->id,
            'is_verified' => true,
            'credits_available' => $operation === 'accept-lesson' ? 1 : 0,
            'credits_reserved' => in_array($operation, ['settle', 'refund', 'reminder-claim'], true) ? 1 : 0,
        ]);

        // El saldo inicial se siembra con un asiento `deposit` real, no
        // escribiendo la columna a mano: LedgerReconciliation deriva el saldo
        // desde el ledger y un saldo sin asiento que lo respalde ES una
        // anomalía. La primera versión de esta sonda tenía ese defecto y el
        // reconciliador lo detectó correctamente.
        $seeded = (int) $profile->credits_available + (int) $profile->credits_reserved;

        if ($seeded > 0) {
            CreditTransaction::create([
                'teacher_profile_id' => $profile->id,
                'idempotency_key' => "teacher:{$profile->id}:probe-seed",
                'type' => 'deposit',
                'amount' => $seeded,
                'description' => 'Saldo inicial (sonda de concurrencia)',
            ]);
        }

        $subject = \App\Models\Subject::firstOrCreate(
            ['name' => 'ConcurrencyProbe'],
            ['level' => 'secundaria']
        );
        $profile->subjects()->syncWithoutDetaching([$subject->id]);

        $parent = \App\Models\User::factory()->create();
        $student = \App\Models\Student::create([
            'parent_user_id' => $parent->id,
            'first_name' => 'Probe',
            'last_name' => 'Student',
            'grade_level' => 'secundaria',
        ]);

        $request = ClassRequest::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'help_needed' => 'Escenario de sonda de concurrencia.',
            'status' => 'open',
        ]);

        if ($operation === 'accept-lesson') {
            // El escenario ES la solicitud: N procesos intentarán aceptarla.
            $this->line((string) $request->id);

            return self::SUCCESS;
        }

        if ($operation === 'approve-recharge') {
            $recharge = RechargeRequest::create([
                'teacher_profile_id' => $profile->id,
                'package_code' => 'inicio',
                'package_name' => 'Inicio',
                'credits' => 5,
                'amount_pen' => '10.00',
                'payment_method' => 'yape',
                'operation_number' => $op = 'PROBE'.strtoupper(uniqid()),
                'operation_number_normalized' => preg_replace('/[^A-Z0-9]/', '', $op),
                'status' => 'pending',
            ]);
            $this->line((string) $recharge->id);

            return self::SUCCESS;
        }

        $request->update(['status' => 'accepted']);
        $lesson = Lesson::create([
            'teacher_profile_id' => $profile->id,
            'student_id' => $student->id,
            'class_request_id' => $request->id,
            'start_time' => $operation === 'reminder-claim' ? now()->addHours(24) : now()->subDays(10),
            'duration_minutes' => 60,
            'status' => $operation === 'reminder-claim' ? 'scheduled' : 'paid',
        ]);

        // También para reminder-claim: sin la reserva, el reconciliador
        // clasifica la lección como NO_LEDGER y reporta anomalía — sería un
        // defecto del escenario, no del código bajo prueba.
        if (true) {
            CreditTransaction::create([
                'teacher_profile_id' => $profile->id,
                'lesson_id' => $lesson->id,
                'idempotency_key' => "lesson:{$lesson->id}:reservation",
                'type' => 'reservation',
                'amount' => 1,
                'description' => 'Reserva (sonda de concurrencia)',
            ]);
        }

        $this->line((string) $lesson->id);

        return self::SUCCESS;
    }

    private function cleanupScenario(): int
    {
        // Limpieza acotada a lo que crea esta sonda, nunca a datos reales.
        DB::transaction(function () {
            $subject = \App\Models\Subject::where('name', 'ConcurrencyProbe')->first();
            if (!$subject) {
                return;
            }

            $requestIds = ClassRequest::where('subject_id', $subject->id)->pluck('id');
            $lessonIds = Lesson::whereIn('class_request_id', $requestIds)->pluck('id');

            CreditTransaction::whereIn('lesson_id', $lessonIds)->delete();
            Lesson::whereIn('id', $lessonIds)->forceDelete();
            ClassRequest::whereIn('id', $requestIds)->delete();

            $profileIds = TeacherProfile::whereHas('subjects', fn ($q) => $q->where('subjects.id', $subject->id))->pluck('id');
            CreditTransaction::whereIn('teacher_profile_id', $profileIds)->delete();
            RechargeRequest::whereIn('teacher_profile_id', $profileIds)->delete();
            DB::table('teacher_subject')->whereIn('teacher_profile_id', $profileIds)->delete();
            $userIds = TeacherProfile::whereIn('id', $profileIds)->pluck('user_id');
            TeacherProfile::whereIn('id', $profileIds)->delete();
            \App\Models\Student::withTrashed()->where('first_name', 'Probe')->forceDelete();
            \App\Models\User::whereIn('id', $userIds)->forceDelete();
            $subject->delete();
        });

        $this->line('cleanup ok');

        return self::SUCCESS;
    }

    // ── Intentos ─────────────────────────────────────────────────────────

    private function attemptAcceptLesson(int $requestId): string
    {
        return DB::transaction(function () use ($requestId) {
            $request = ClassRequest::whereKey($requestId)->lockForUpdate()->firstOrFail();

            if ($request->status !== 'open') {
                return 'LOST';
            }

            $profile = TeacherProfile::whereHas('subjects', fn ($q) => $q->where('subjects.id', $request->subject_id))
                ->lockForUpdate()
                ->firstOrFail();

            if ($profile->credits_available < 1) {
                return 'LOST_NO_CREDITS';
            }

            $lesson = Lesson::create([
                'teacher_profile_id' => $profile->id,
                'student_id' => $request->student_id,
                'class_request_id' => $request->id,
                'start_time' => now()->addDays(2),
                'duration_minutes' => 60,
                'status' => 'scheduled',
            ]);

            $profile->update([
                'credits_available' => $profile->credits_available - 1,
                'credits_reserved' => $profile->credits_reserved + 1,
            ]);

            CreditTransaction::create([
                'teacher_profile_id' => $profile->id,
                'lesson_id' => $lesson->id,
                'idempotency_key' => "lesson:{$lesson->id}:reservation",
                'type' => 'reservation',
                'amount' => 1,
                'description' => 'Reserva (sonda de concurrencia)',
            ]);

            $request->update(['status' => 'accepted']);

            return 'WON';
        });
    }

    private function attemptSettle(int $lessonId): string
    {
        app(LessonSettlementService::class)->consume(
            Lesson::findOrFail($lessonId), null, 'Sonda de concurrencia', 'auto_settled', false
        );

        return 'DONE';
    }

    private function attemptRefund(int $lessonId): string
    {
        app(LessonSettlementService::class)->refund(
            Lesson::findOrFail($lessonId), null, 'Sonda de concurrencia', 'admin_refunded'
        );

        return 'DONE';
    }

    private function attemptApproveRecharge(int $rechargeId): string
    {
        app(RechargeApprovalService::class)->credit(RechargeRequest::findOrFail($rechargeId), null);

        return 'DONE';
    }

    private function attemptReminderClaim(int $lessonId): string
    {
        $claimed = Lesson::whereKey($lessonId)
            ->whereNull('reminder_24h_sent_at')
            ->where('status', 'scheduled')
            ->update(['reminder_24h_sent_at' => now()]);

        return $claimed === 1 ? 'WON' : 'LOST';
    }
}
