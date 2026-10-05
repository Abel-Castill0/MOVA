<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Separación staging/producción: un sitio público (indexación activa) no puede
 * servir una base con cuentas de prueba. Evita apuntar el dominio final a la
 * base de staging por error.
 */
class HealthCheckEnvironmentSeparationTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = 'QA_FIXTURE_DATA_IN_PUBLIC_SITE';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'production';
    }

    /** @return array{codes: list<string>, checks: array<string,string>} */
    private function health(bool $indexing): array
    {
        config(['seo.indexing_enabled' => $indexing]);
        Artisan::call('mova:health-check', ['--json' => true]);
        $json = json_decode(Artisan::output(), true);

        return ['codes' => array_column($json['warnings'], 'code'), 'checks' => $json['checks']];
    }

    public function test_a_public_site_with_qa_accounts_raises_the_incident(): void
    {
        User::factory()->create(['email' => 'admin-phase2b@mova.test']);

        $result = $this->health(indexing: true);

        $this->assertContains(self::CODE, $result['codes']);
        $this->assertSame('1', $result['checks']['qa_fixture_users']);
    }

    public function test_each_fixture_email_pattern_is_detected(): void
    {
        foreach (['a@mova.test', 'b@example.test', 'c@cualquier.test', 'qa-familia@gmail.com'] as $email) {
            User::factory()->create(['email' => $email]);
        }

        $this->assertSame('4', $this->health(indexing: true)['checks']['qa_fixture_users']);
    }

    public function test_a_public_site_with_only_real_looking_accounts_is_clean(): void
    {
        User::factory()->create(['email' => 'familia.quispe@gmail.com']);
        User::factory()->create(['email' => 'profesor@institucion.edu.pe']);

        $result = $this->health(indexing: true);

        $this->assertNotContains(self::CODE, $result['codes']);
        $this->assertSame('0', $result['checks']['qa_fixture_users']);
    }

    public function test_staging_with_indexing_off_is_allowed_to_hold_qa_accounts(): void
    {
        User::factory()->create(['email' => 'admin-phase2b@mova.test']);

        $result = $this->health(indexing: false);

        $this->assertNotContains(self::CODE, $result['codes']);
        $this->assertArrayNotHasKey('qa_fixture_users', $result['checks']);
    }

    public function test_the_check_only_applies_in_production(): void
    {
        $this->app['env'] = 'local';
        User::factory()->create(['email' => 'admin-phase2b@mova.test']);

        $this->assertNotContains(self::CODE, $this->health(indexing: true)['codes']);
    }
}
