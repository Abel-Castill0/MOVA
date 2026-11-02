<?php

namespace Tests\Feature;

use App\Models\ClassRequest;
use App\Models\CreditTransaction;
use App\Models\Lesson;
use App\Models\RechargeRequest;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\RechargeApprovalService;
use App\Support\LedgerReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

class ConcurrencyVerifyIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_qa_probe_and_verifier_reject_missing_or_non_qa_connections(): void
    {
        foreach ([
            ['mova:concurrency-probe', ['operation' => 'approve-recharge', '--setup' => true]],
            ['mova:concurrency-probe', ['operation' => 'approve-recharge', '--cleanup' => true]],
            ['mova:concurrency-verify', ['operation' => 'approve-recharge', 'scenario' => 1]],
        ] as [$command, $args]) {
            foreach ([[], ['--connection' => 'sqlite']] as $connection) {
                try {
                    $this->artisan($command, $args + $connection)->run();
                    $this->fail('El comando QA debió rechazar la conexión ausente/no permitida.');
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString('--connection=mysql_qa', $e->getMessage());
                }
            }
        }
    }

    public function test_commands_reject_a_remote_host_from_resolved_laravel_configuration(): void
    {
        $originalHost = config('database.connections.mysql_qa.host');
        try {
            config(['database.connections.mysql_qa.host' => 'remote-db.example.invalid']);

            foreach (['mova:concurrency-probe', 'mova:concurrency-verify'] as $command) {
                try {
                    $args = $command === 'mova:concurrency-probe'
                        ? ['operation' => 'approve-recharge', '--setup' => true]
                        : ['operation' => 'approve-recharge', 'scenario' => 1];
                    Artisan::call($command, $args + ['--connection' => 'mysql_qa']);
                    $this->fail('Se debía rechazar el host remoto configurado en Laravel.');
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString('loopback', $e->getMessage());
                }
            }
        } finally {
            config(['database.connections.mysql_qa.host' => $originalHost]);
        }
    }

    public function test_verifier_ignores_an_unrelated_broken_ledger_when_its_scenario_is_healthy(): void
    {
        if (config('database.default') !== 'mysql_qa') {
            $this->markTestSkipped('Requiere MySQL efímero mysql_qa; SQLite nunca valida este escenario destructivo.');
        }

        // Anomalía de un perfil que NO pertenece al escenario verificado.
        TeacherProfile::create([
            'user_id' => User::factory()->create()->id,
            'is_verified' => true,
            'credits_available' => 99,
            'credits_reserved' => 0,
        ]);
        $recharge = $this->rechargeScenario(depositAmount: 5);

        $this->assertFalse(app(LedgerReconciliation::class)->run()['healthy']);
        $this->artisan('mova:concurrency-verify', [
            'operation' => 'approve-recharge',
            'scenario' => $recharge->id,
            '--connection' => 'mysql_qa',
        ])->assertExitCode(0);
    }

    public function test_verifier_still_fails_when_its_own_scenario_has_a_ledger_mismatch(): void
    {
        if (config('database.default') !== 'mysql_qa') {
            $this->markTestSkipped('Requiere MySQL efímero mysql_qa.');
        }

        // La recarga tiene un depósito, pero su saldo almacenado no coincide
        // con el importe del asiento: esto SÍ pertenece al escenario.
        $recharge = $this->rechargeScenario(depositAmount: 4);

        $this->artisan('mova:concurrency-verify', [
            'operation' => 'approve-recharge',
            'scenario' => $recharge->id,
            '--connection' => 'mysql_qa',
        ])->assertExitCode(1);
    }

    public function test_cleaning_scenario_a_never_erases_scenario_b_or_its_teacher(): void
    {
        $this->requireMysqlQa();

        $a = $this->setupProbe('accept-lesson');
        $b = $this->setupProbe('accept-lesson');

        try {
            $requestA = ClassRequest::findOrFail($a);
            $requestB = ClassRequest::findOrFail($b);
            $subjectA = $requestA->subject;
            $subjectB = $requestB->subject;
            $teacherA = $subjectA->teachers()->firstOrFail();
            $teacherB = $subjectB->teachers()->firstOrFail();
            $this->assertNotEquals($subjectA->id, $subjectB->id);
            $this->assertNotEquals($teacherA->id, $teacherB->id);

            foreach ([$a, $b] as $id) {
                $this->assertSame(0, Artisan::call('mova:concurrency-probe', [
                    'operation' => 'accept-lesson', '--scenario' => $id,
                    '--connection' => 'mysql_qa',
                ]));
            }
            $this->assertSame($teacherA->id, Lesson::where('class_request_id', $a)->value('teacher_profile_id'));
            $this->assertSame($teacherB->id, Lesson::where('class_request_id', $b)->value('teacher_profile_id'));

            $this->assertSame(0, $this->cleanupProbe('accept-lesson', $a));
            $this->assertFalse(ClassRequest::whereKey($a)->exists());
            $this->assertFalse(TeacherProfile::whereKey($teacherA->id)->exists());
            $this->assertTrue(ClassRequest::whereKey($b)->exists());
            $this->assertTrue(TeacherProfile::whereKey($teacherB->id)->exists());
            $this->assertTrue(Subject::whereKey($subjectB->id)->exists());
            $this->artisan('mova:concurrency-verify', [
                'operation' => 'accept-lesson', 'scenario' => $b,
                '--connection' => 'mysql_qa',
            ])->assertExitCode(0);
        } finally {
            if (ClassRequest::whereKey($a)->exists()) {
                $this->cleanupProbe('accept-lesson', $a);
            }
            if (ClassRequest::whereKey($b)->exists()) {
                $this->cleanupProbe('accept-lesson', $b);
            }
        }
    }

    public function test_worker_does_not_disguise_unexpected_service_errors_as_a_lost_race(): void
    {
        $this->requireMysqlQa();
        $id = $this->setupProbe('approve-recharge');

        try {
            $this->mock(RechargeApprovalService::class)
                ->shouldReceive('credit')
                ->once()
                ->andThrow(new RuntimeException('SECRET_SQL_MESSAGE_DO_NOT_PRINT'));

            $this->assertSame(1, Artisan::call('mova:concurrency-probe', [
                'operation' => 'approve-recharge', '--scenario' => $id,
                '--connection' => 'mysql_qa',
            ]));
            $this->assertStringContainsString('result=ERROR', Artisan::output());
            $this->assertStringNotContainsString('SECRET_SQL_MESSAGE_DO_NOT_PRINT', Artisan::output());
        } finally {
            $this->cleanupProbe('approve-recharge', $id);
        }
    }

    public function test_cleanup_without_scenario_and_unknown_operation_cannot_modify_qa_data(): void
    {
        $this->requireMysqlQa();
        $id = $this->setupProbe('accept-lesson');

        try {
            $this->assertSame(1, Artisan::call('mova:concurrency-probe', [
                'operation' => 'accept-lesson', '--cleanup' => true,
                '--connection' => 'mysql_qa',
            ]));
            $this->assertSame(1, Artisan::call('mova:concurrency-probe', [
                'operation' => 'not-a-supported-operation', '--setup' => true,
                '--connection' => 'mysql_qa',
            ]));
            $this->assertTrue(ClassRequest::whereKey($id)->exists());
            $this->assertSame(1, Subject::where('name', 'like', 'ConcurrencyProbe%')->count());
        } finally {
            $this->cleanupProbe('accept-lesson', $id);
        }
    }

    public function test_verifier_refuses_a_ledger_entry_without_terminal_lesson_status(): void
    {
        $this->requireMysqlQa();
        $id = $this->setupProbe('settle');

        try {
            $lesson = Lesson::findOrFail($id);
            CreditTransaction::create([
                'teacher_profile_id' => $lesson->teacher_profile_id,
                'lesson_id' => $id,
                'idempotency_key' => "lesson:{$id}:consumption",
                'type' => 'consumption',
                'amount' => 1,
                'description' => 'Asiento incompleto de QA',
            ]);
            $lesson->teacherProfile->update(['credits_reserved' => 0]);

            $this->artisan('mova:concurrency-verify', [
                'operation' => 'settle', 'scenario' => $id,
                '--connection' => 'mysql_qa',
            ])->assertExitCode(1);
        } finally {
            $this->cleanupProbe('settle', $id);
        }
    }

    private function requireMysqlQa(): void
    {
        if (config('database.default') !== 'mysql_qa') {
            $this->markTestSkipped('Requiere MySQL efímero mysql_qa.');
        }
    }

    private function setupProbe(string $operation): int
    {
        $this->assertSame(0, Artisan::call('mova:concurrency-probe', [
            'operation' => $operation, '--setup' => true,
            '--connection' => 'mysql_qa',
        ]));
        $id = trim(Artisan::output());
        $this->assertMatchesRegularExpression('/^[1-9][0-9]*$/', $id);

        return (int) $id;
    }

    private function cleanupProbe(string $operation, int $id): int
    {
        return Artisan::call('mova:concurrency-probe', [
            'operation' => $operation, '--cleanup' => true,
            '--scenario' => $id, '--connection' => 'mysql_qa',
        ]);
    }

    private function rechargeScenario(int $depositAmount): RechargeRequest
    {
        $profile = TeacherProfile::create([
            'user_id' => User::factory()->create()->id,
            'is_verified' => true,
            'credits_available' => 5,
            'credits_reserved' => 0,
        ]);
        $recharge = RechargeRequest::create([
            'teacher_profile_id' => $profile->id,
            'package_code' => 'inicio',
            'package_name' => 'Inicio',
            'credits' => 5,
            'amount_pen' => '10.00',
            'payment_method' => 'culqi',
            'operation_number' => null,
            'operation_number_normalized' => null,
            'status' => 'approved',
        ]);
        CreditTransaction::create([
            'teacher_profile_id' => $profile->id,
            'recharge_request_id' => $recharge->id,
            'idempotency_key' => "recharge:{$recharge->id}:deposit",
            'type' => 'deposit',
            'amount' => $depositAmount,
            'description' => 'Depósito de escenario QA.',
        ]);

        return $recharge;
    }
}
