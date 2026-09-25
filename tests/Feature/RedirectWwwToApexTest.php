<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * App\Http\Middleware\RedirectWwwToApex — preparación para el cutover de
 * producción (APP_URL=https://movaeduca.me). Cobertura de la matriz
 * completa: solo www.<host(APP_URL)> redirige, path/query se preservan,
 * staging y el FQDN de Azure quedan intactos, y solo GET/HEAD (nunca
 * webhooks POST).
 */
class RedirectWwwToApexTest extends TestCase
{
    use RefreshDatabase;

    public function test_www_of_the_canonical_apex_redirects_to_https_apex_preserving_path_and_query(): void
    {
        config(['app.url' => 'https://movaeduca.me']);

        $response = $this->get('http://www.movaeduca.me/marketplace?subject=matematicas&page=2');

        $response->assertStatus(301);
        $response->assertRedirect('https://movaeduca.me/marketplace?subject=matematicas&page=2');
    }

    public function test_head_request_to_www_also_redirects(): void
    {
        config(['app.url' => 'https://movaeduca.me']);

        $response = $this->call('HEAD', 'http://www.movaeduca.me/');

        $response->assertStatus(301);
        $response->assertRedirect('https://movaeduca.me/');
    }

    public function test_the_canonical_apex_itself_is_never_redirected(): void
    {
        config(['app.url' => 'https://movaeduca.me']);

        $response = $this->get('https://movaeduca.me/');

        $response->assertStatus(200);
    }

    public function test_staging_is_unaffected_because_its_www_host_does_not_exist(): void
    {
        // APP_URL real de staging — el host que dispararía este guard sería
        // www.staging.movaeduca.me, que nadie visita y no resuelve en DNS.
        config(['app.url' => 'https://staging.movaeduca.me']);

        $response = $this->get('https://staging.movaeduca.me/');

        $response->assertStatus(200);
    }

    public function test_the_azure_generated_fqdn_is_never_redirected(): void
    {
        config(['app.url' => 'https://staging.movaeduca.me']);

        $response = $this->get('http://mova-web.whitecliff-88cda913.mexicocentral.azurecontainerapps.io/');

        $response->assertStatus(200);
    }

    public function test_a_post_to_www_is_never_redirected_so_webhooks_are_unaffected(): void
    {
        config(['app.url' => 'https://movaeduca.me']);

        // No existe una ruta POST '/' real — lo que importa es demostrar
        // que el middleware nunca intercepta con un 301 antes de que la
        // request llegue al router; un 404 confirma que pasó de largo.
        $response = $this->post('http://www.movaeduca.me/some-path');

        $response->assertStatus(404);
    }

    public function test_no_redirect_loop_the_apex_response_from_the_redirect_target_is_never_another_redirect(): void
    {
        config(['app.url' => 'https://movaeduca.me']);

        $first = $this->get('http://www.movaeduca.me/');
        $first->assertStatus(301);

        $second = $this->get($first->headers->get('Location'));
        $second->assertStatus(200);
    }
}
