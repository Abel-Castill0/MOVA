<?php

namespace Tests\Feature;

use App\Models\Complaint;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->expectsOutputToContain('scenario=0')
            ->assertSuccessful();
    }
}
