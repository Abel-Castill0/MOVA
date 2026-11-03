<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * El runner independiente crea usuarios, recargas, lecciones y constancias
 * reales en MySQL QA; la conexión remota debe rechazarse antes del fixture.
 */
class QaAccountClosureRaceGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_script_refuses_remote_qa_host_before_opening_a_connection(): void
    {
        $result = $this->runProbe('duplicate-closure', [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql_qa',
            'DB_HOST' => 'remote-qa.example.invalid',
        ]);

        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('QA connection host rejected', $result->getErrorOutput());
        $this->assertStringContainsString('loopback', $result->getErrorOutput());
    }

    public function test_script_refuses_production_environment_before_creating_fixture(): void
    {
        $result = $this->runProbe('duplicate-closure', [
            'APP_ENV' => 'production',
            'DB_CONNECTION' => 'mysql_qa',
            'DB_HOST' => '127.0.0.1',
        ]);

        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('Requires APP_ENV=testing', $result->getErrorOutput());
    }

    public function test_unknown_scenario_returns_failure_without_creating_accounts(): void
    {
        $this->requireMysqlQa();

        $result = $this->runProbe('unknown-scenario', [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql_qa',
            'DB_HOST' => '127.0.0.1',
        ]);

        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('Unknown account-closure race scenario', $result->getErrorOutput());
    }

    public function test_worker_refuses_external_fixture_path_before_processing_any_account(): void
    {
        $this->requireMysqlQa();

        $result = $this->runProbe('--worker', [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql_qa',
            'DB_HOST' => '127.0.0.1',
        ], [base_path(), 'leader']);

        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('Worker fixture outside QA temporary directory', $result->getErrorOutput());
    }

    public function test_worker_refuses_valid_qa_directory_with_foreign_account_ids(): void
    {
        $this->requireMysqlQa();

        $run = (string) Str::uuid();
        $directory = sys_get_temp_dir().'/mova-closure-race-'.$run;
        $this->assertTrue(mkdir($directory, 0700));
        try {
            $fixture = [
                'run' => $run, 'scenario' => 'duplicate-closure', 'leader' => 'close', 'follower' => 'close',
                'real_notifications' => false,
                'parent' => 999999, 'teacher_user' => 999998, 'admin' => 999997,
                'teacher' => 999996, 'student' => 999995, 'subject' => 999994,
                'request' => 999993,
            ];
            file_put_contents($directory.'/fixture.json', json_encode($fixture, JSON_THROW_ON_ERROR));
            $environment = [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'mysql_qa',
                'DB_HOST' => '127.0.0.1',
            ];
            $result = $this->runProbe('--worker', $environment, [$directory, 'leader']);

            $this->assertFalse($result->isSuccessful());
            $this->assertStringContainsString('QA fixture does not own all referenced accounts', $result->getErrorOutput());

            $fixture['leader'] = 'settle';
            file_put_contents($directory.'/fixture.json', json_encode($fixture, JSON_THROW_ON_ERROR));
            $changedOperation = $this->runProbe('--worker', $environment, [$directory, 'leader']);
            $this->assertFalse($changedOperation->isSuccessful());
            $this->assertStringContainsString('QA fixture does not match a declared race scenario', $changedOperation->getErrorOutput());
        } finally {
            if (is_file($directory.'/fixture.json')) {
                unlink($directory.'/fixture.json');
            }
            rmdir($directory);
        }
    }

    private function requireMysqlQa(): void
    {
        if (config('database.default') !== 'mysql_qa') {
            $this->markTestSkipped('Las comprobaciones de escenario usan solo la suite MySQL QA.');
        }
    }

    private function runProbe(string $operation, array $environment, array $args = []): Process
    {
        $process = new Process([PHP_BINARY, base_path('scripts/qa-account-closure-race.php'), $operation, ...$args], base_path(), $environment);
        $process->run();

        return $process;
    }
}
