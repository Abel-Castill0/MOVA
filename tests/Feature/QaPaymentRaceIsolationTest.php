<?php

namespace Tests\Feature;

use App\Models\PaymentOrder;
use App\Models\RechargeRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\CulqiPaymentReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

/** La sonda Culqi no debe tocar otra sonda ni una base MySQL remota. */
class QaPaymentRaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_rejects_missing_connection_and_non_qa_connection_before_any_write(): void
    {
        foreach ([[], ['--connection' => 'sqlite']] as $connection) {
            foreach (['--setup', '--cleanup'] as $mode) {
                try {
                    Artisan::call('mova:qa-payment-race', [
                        'operation' => 'reconcile-paid', $mode => true,
                    ] + $connection);
                    $this->fail('La sonda debe exigir mysql_qa explícitamente.');
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString('--connection=mysql_qa', $e->getMessage());
                }
            }
        }
    }

    public function test_resolved_remote_host_is_rejected_before_any_write(): void
    {
        $originalHost = config('database.connections.mysql_qa.host');
        try {
            config(['database.connections.mysql_qa.host' => 'remote-mysql.example.invalid']);
            try {
                Artisan::call('mova:qa-payment-race', [
                    'operation' => 'reconcile-paid', '--setup' => true,
                    '--connection' => 'mysql_qa',
                ]);
                $this->fail('No se debe permitir un host remoto aunque la BD se llame mova_qa.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('loopback', $e->getMessage());
            }
        } finally {
            config(['database.connections.mysql_qa.host' => $originalHost]);
        }
    }

    public function test_cleanup_of_scenario_a_preserves_scenario_b_with_its_order_profile_and_user(): void
    {
        $this->requireMysqlQa();
        $a = $this->setupProbe('reconcile-paid');
        $b = $this->setupProbe('reconcile-paid');

        try {
            $rechargeA = RechargeRequest::findOrFail($a);
            $rechargeB = RechargeRequest::findOrFail($b);
            $profileA = $rechargeA->teacherProfile;
            $profileB = $rechargeB->teacherProfile;
            $userB = $profileB->user;

            $this->assertNotEquals($profileA->id, $profileB->id);
            $this->assertSame(1, PaymentOrder::where('recharge_request_id', $b)->count());
            $this->assertSame(1, Artisan::call('mova:qa-payment-race', [
                'operation' => 'reconcile-paid', '--cleanup' => true,
                '--connection' => 'mysql_qa',
            ]));
            $this->assertTrue(RechargeRequest::whereKey($a)->exists());

            $this->assertSame(0, $this->cleanupProbe('reconcile-paid', $a));
            $this->assertFalse(RechargeRequest::whereKey($a)->exists());
            $this->assertFalse(TeacherProfile::whereKey($profileA->id)->exists());
            $this->assertTrue(RechargeRequest::whereKey($b)->exists());
            $this->assertTrue(TeacherProfile::whereKey($profileB->id)->exists());
            $this->assertTrue(User::whereKey($userB->id)->exists());
            $this->assertSame(1, PaymentOrder::where('recharge_request_id', $b)->count());
        } finally {
            if (RechargeRequest::whereKey($a)->exists()) {
                $this->cleanupProbe('reconcile-paid', $a);
            }
            if (RechargeRequest::whereKey($b)->exists()) {
                $this->cleanupProbe('reconcile-paid', $b);
            }
        }
    }

    public function test_unknown_operation_does_not_create_any_scenario(): void
    {
        $this->requireMysqlQa();
        $before = RechargeRequest::count();

        $this->assertSame(1, Artisan::call('mova:qa-payment-race', [
            'operation' => 'unknown-operation', '--setup' => true,
            '--connection' => 'mysql_qa',
        ]));
        $this->assertSame($before, RechargeRequest::count());
    }

    public function test_failed_refund_scenario_setup_rolls_back_all_synthetic_rows(): void
    {
        $this->requireMysqlQa();
        $before = [
            'users' => User::where('email', 'like', 'qa-pay-race-%@mova.test')->count(),
            'profiles' => TeacherProfile::count(),
            'recharges' => RechargeRequest::count(),
            'orders' => PaymentOrder::count(),
        ];

        $this->mock(CulqiPaymentReconciliationService::class)
            ->shouldReceive('applyTruth')
            ->once()
            ->andThrow(new RuntimeException('QA simulated setup failure'));

        try {
            Artisan::call('mova:qa-payment-race', [
                'operation' => 'reconcile-refund', '--setup' => true,
                '--connection' => 'mysql_qa',
            ]);
            $this->fail('Un setup que falla debe propagar la excepción.');
        } catch (RuntimeException $e) {
            $this->assertSame('QA simulated setup failure', $e->getMessage());
        }

        $this->assertSame($before['users'], User::where('email', 'like', 'qa-pay-race-%@mova.test')->count());
        $this->assertSame($before['profiles'], TeacherProfile::count());
        $this->assertSame($before['recharges'], RechargeRequest::count());
        $this->assertSame($before['orders'], PaymentOrder::count());
    }

    public function test_charge_attempt_rejects_any_external_stub_url_before_a_network_request(): void
    {
        $this->requireMysqlQa();
        $id = $this->setupProbe('charge-attempt');

        try {
            $this->assertSame(1, Artisan::call('mova:qa-payment-race', [
                'operation' => 'charge-attempt', '--scenario' => $id,
                '--stub-url' => 'https://api.culqi.com',
                '--connection' => 'mysql_qa',
            ]));
            $this->assertStringContainsString('result=error:', Artisan::output());
            $this->assertSame(0, PaymentOrder::where('recharge_request_id', $id)->count());
        } finally {
            $this->cleanupProbe('charge-attempt', $id);
        }
    }

    private function requireMysqlQa(): void
    {
        if (config('database.default') !== 'mysql_qa') {
            $this->markTestSkipped('La creación y limpieza solo se prueban en MySQL QA efímero.');
        }
    }

    private function setupProbe(string $operation): int
    {
        $this->assertSame(0, Artisan::call('mova:qa-payment-race', [
            'operation' => $operation, '--setup' => true,
            '--connection' => 'mysql_qa',
        ]));
        $output = Artisan::output();
        $this->assertMatchesRegularExpression('/^scenario=[1-9][0-9]*\s*$/', $output);

        return (int) trim(substr($output, strlen('scenario=')));
    }

    private function cleanupProbe(string $operation, int $id): int
    {
        return Artisan::call('mova:qa-payment-race', [
            'operation' => $operation, '--cleanup' => true,
            '--scenario' => $id, '--connection' => 'mysql_qa',
        ]);
    }
}
