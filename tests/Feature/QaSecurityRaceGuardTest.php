<?php

namespace Tests\Feature;

use App\Models\Complaint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * mova:qa-security-race borra/crea filas: debe fallar cerrado ANTES de
 * tocar datos salvo con --connection=mysql_qa resuelta a la base mova_qa.
 */
class QaSecurityRaceGuardTest extends TestCase
{
    use RefreshDatabase;

    private Complaint $existing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->existing = Complaint::file([
            'type' => 'reclamo', 'consumer_name' => 'Real', 'document_type' => 'DNI',
            'document_number' => '12345678', 'address' => 'Av. 1', 'email' => 'qa-race@mova.test',
            'good_type' => 'servicio', 'good_description' => 'x', 'detail' => 'Detalle real.',
            'consumer_request' => 'Pedido', 'is_minor' => false,
        ]);
    }

    private function assertUntouched(): void
    {
        $this->assertTrue(Complaint::whereKey($this->existing->id)->exists(), 'La hoja existente no debe borrarse.');
        $this->assertSame(1, (int) DB::table('complaint_sequences')->where('year', now()->year)->value('last_number'));
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public static function destructiveModes(): array
    {
        return [
            'setup' => [['operation' => 'complaints-empty', '--setup' => true]],
            'cleanup' => [['operation' => 'complaints-empty', '--cleanup' => true]],
            'worker' => [['operation' => 'complaints-empty', '--worker' => 1]],
        ];
    }

    /** @dataProvider destructiveModes */
    public function test_without_connection_it_aborts_before_touching_data(array $args): void
    {
        try {
            $this->artisan('mova:qa-security-race', $args)->run();
            $this->fail('Debió abortar sin --connection.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('--connection=mysql_qa', $e->getMessage());
        }

        $this->assertUntouched();
    }

    /** @dataProvider destructiveModes */
    public function test_any_other_connection_is_refused_before_touching_data(array $args): void
    {
        try {
            $this->artisan('mova:qa-security-race', $args + ['--connection' => 'sqlite'])->run();
            $this->fail('Debió rechazar una conexión distinta de mysql_qa.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('--connection=mysql_qa', $e->getMessage());
        }

        $this->assertUntouched();
    }

    public function test_mysql_qa_pointing_to_a_database_other_than_mova_qa_is_refused(): void
    {
        if (config('database.default') === 'mysql_qa') {
            $this->markTestSkipped('En la suite MySQL mysql_qa sí resuelve a mova_qa (caso válido, cubierto abajo).');
        }

        // mysql_qa redirigida a la base de ESTA suite (no mova_qa).
        config(['database.connections.mysql_qa' => config('database.connections.'.config('database.default'))]);

        try {
            $this->artisan('mova:qa-security-race', ['operation' => 'complaints-empty', '--setup' => true, '--connection' => 'mysql_qa'])->run();
            $this->fail('QaDatabaseGuard debió rechazar la base.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('MOVA QA GUARD', $e->getMessage());
        }

        $this->assertUntouched();
    }

    public function test_correct_qa_connection_still_runs_the_harness(): void
    {
        if (config('database.default') !== 'mysql_qa') {
            $this->markTestSkipped('Requiere la suite MySQL (mysql_qa -> mova_qa).');
        }

        $this->artisan('mova:qa-security-race', ['operation' => 'complaints-empty', '--setup' => true, '--connection' => 'mysql_qa'])
            ->expectsOutputToContain('scenario=c-')
            ->assertSuccessful();

        $this->assertUntouched();
    }

    public function test_two_complaint_scenarios_and_cleanup_are_isolated_from_each_other_and_real_year(): void
    {
        $this->requireMysqlQa();

        $scenarioA = $this->setupScenario('complaints-empty');
        $scenarioB = $this->setupScenario('complaints-seeded');
        $yearA = (int) explode('-', $scenarioA)[1];
        $yearB = (int) explode('-', $scenarioB)[1];

        $this->assertNotSame($scenarioA, $scenarioB);
        $this->assertNotSame($yearA, $yearB, 'Cada carrera de reclamaciones debe reservar su propio año QA.');
        $this->assertSame(0, DB::table('complaints')->where('code', 'like', "MOVA-${yearA}-%")->count());
        $this->assertSame(3, DB::table('complaints')->where('code', 'like', "MOVA-${yearB}-%")->count());

        $this->assertSame(0, Artisan::call('mova:qa-security-race', [
            'operation' => 'complaints-empty', '--cleanup' => true, '--scenario' => $scenarioA, '--connection' => 'mysql_qa',
        ]));
        $this->assertSame(0, DB::table('complaint_sequences')->where('year', $yearA)->count());
        $this->assertSame(3, DB::table('complaints')->where('code', 'like', "MOVA-${yearB}-%")->count());
        $this->assertSame(3, (int) DB::table('complaint_sequences')->where('year', $yearB)->value('last_number'));
        $this->assertUntouched();

        $this->assertSame(0, Artisan::call('mova:qa-security-race', [
            'operation' => 'complaints-seeded', '--cleanup' => true, '--scenario' => $scenarioB, '--connection' => 'mysql_qa',
        ]));
        $this->assertSame(0, DB::table('complaint_sequences')->where('year', $yearB)->count());
        $this->assertUntouched();
    }

    public function test_cleanup_without_scenario_refuses_to_delete_any_complaint(): void
    {
        $this->requireMysqlQa();
        $scenario = $this->setupScenario('complaints-seeded');
        $year = (int) explode('-', $scenario)[1];

        try {
            Artisan::call('mova:qa-security-race', [
                'operation' => 'complaints-seeded', '--cleanup' => true, '--connection' => 'mysql_qa',
            ]);
            $this->fail('Cleanup sin escenario no debe continuar.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Escenario QA inválido', $e->getMessage());
        }

        $this->assertSame(3, DB::table('complaints')->where('code', 'like', "MOVA-${year}-%")->count());
        $this->assertUntouched();
    }

    public function test_complaint_cleanup_refuses_foreign_rows_in_the_reserved_year(): void
    {
        $this->requireMysqlQa();
        $scenario = $this->setupScenario('complaints-empty');
        $year = (int) explode('-', $scenario)[1];
        $previousClock = Carbon::getTestNow();
        Carbon::setTestNow(now()->setYear($year));
        try {
            $foreign = Complaint::file([
                'type' => 'reclamo', 'consumer_name' => 'Otro ensayo QA', 'document_type' => 'DNI',
                'document_number' => '12345678', 'address' => 'Av. QA 321', 'email' => 'other.qa@mova.test',
                'good_type' => 'servicio', 'good_description' => 'x', 'detail' => 'Otra prueba segura.',
                'consumer_request' => 'Pedido', 'is_minor' => false,
            ]);
        } finally {
            Carbon::setTestNow($previousClock);
        }

        $this->assertSame(1, Artisan::call('mova:qa-security-race', [
            'operation' => 'complaints-empty', '--cleanup' => true, '--scenario' => $scenario, '--connection' => 'mysql_qa',
        ]));
        $this->assertTrue(Complaint::whereKey($foreign->id)->exists());
        $this->assertSame(1, DB::table('complaint_sequences')->where('year', $year)->count());
        $this->assertUntouched();
    }

    public function test_mfa_cleanup_deletes_only_selected_qa_admin(): void
    {
        $this->requireMysqlQa();
        $idA = (int) $this->setupScenario('totp');
        $idB = (int) $this->setupScenario('recovery');

        $this->assertNotSame($idA, $idB);
        $this->assertSame(1, Artisan::call('mova:qa-security-race', [
            'operation' => 'totp', '--cleanup' => true, '--connection' => 'mysql_qa',
        ]));
        $this->assertDatabaseHas('users', ['id' => $idA]);
        $this->assertDatabaseHas('users', ['id' => $idB]);
        $this->assertSame(0, Artisan::call('mova:qa-security-race', [
            'operation' => 'totp', '--cleanup' => true, '--scenario' => $idA, '--connection' => 'mysql_qa',
        ]));
        $this->assertDatabaseMissing('users', ['id' => $idA]);
        $this->assertDatabaseHas('users', ['id' => $idB]);
        $this->assertUntouched();
    }

    public function test_worker_exception_fails_instead_of_reporting_a_successful_race(): void
    {
        $this->requireMysqlQa();
        $this->assertSame(1, Artisan::call('mova:qa-security-race', [
            'operation' => 'totp', '--scenario' => 999999999, '--code' => '000000',
            '--connection' => 'mysql_qa',
        ]));
        $this->assertStringContainsString('result=error:', Artisan::output());
        $this->assertUntouched();
    }

    private function requireMysqlQa(): void
    {
        if (config('database.default') !== 'mysql_qa') {
            $this->markTestSkipped('Los casos de limpieza necesitan mysql_qa aislado.');
        }
    }

    private function setupScenario(string $operation): string
    {
        $this->assertSame(0, Artisan::call('mova:qa-security-race', [
            'operation' => $operation, '--setup' => true, '--connection' => 'mysql_qa',
        ]));
        // Artisan::output() consume el buffer: reutilizar el snapshot exacto
        // para la aserción y la captura del ID.
        $output = Artisan::output();
        $this->assertMatchesRegularExpression('/scenario=([^ ]+)/', $output);
        preg_match('/scenario=([^ ]+)/', $output, $matches);

        return $matches[1];
    }
}
