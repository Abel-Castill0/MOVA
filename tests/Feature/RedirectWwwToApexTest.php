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
 *
 * API GUARD (hallazgo de revisión externa): el filtro GET/HEAD no bastaba
 * — MOVA expone `GET /api/webhooks/whatsapp` (verificación de webhook de
 * Meta, real), que es GET y por tanto SÍ coincidía con el guard original.
 * Los tests de este bloque golpean la ruta API real (no una reimplementada
 * aparte) para demostrar el contrato con el HTTP real de MOVA, salvo
 * cuando el controller introduciría una dependencia frágil — no es el
 * caso aquí: WhatsAppWebhookController::verify() sin configurar responde
 * 403 de forma determinista, nunca 500 ni redirect.
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

    /**
     * (A) GET a la ruta API real de verificación de webhook de WhatsApp
     * sobre www.<apex> — nunca debe ser un 301. Sin token configurado el
     * controller responde 403 de forma determinista (nunca 500); lo único
     * que este test necesita demostrar es que el middleware nunca lo
     * convirtió antes en un redirect.
     */
    public function test_get_api_whatsapp_webhook_on_www_is_never_redirected(): void
    {
        config(['app.url' => 'https://movaeduca.me']);

        $response = $this->get('http://www.movaeduca.me/api/webhooks/whatsapp?hub_mode=subscribe&hub_challenge=abc123&hub_verify_token=x');

        $response->assertStatus(403);
    }

    /**
     * (B) Cualquier GET bajo /api sobre www.<apex> nunca se canonicaliza,
     * exista o no la ruta — el guard pasa de largo por path, no por si el
     * router termina resolviéndola.
     */
    public function test_any_get_under_api_on_www_is_never_canonicalized(): void
    {
        config(['app.url' => 'https://movaeduca.me']);

        $response = $this->get('http://www.movaeduca.me/api/this-route-does-not-exist');

        $response->assertStatus(404);
    }

    public function test_get_api_root_on_www_is_never_canonicalized(): void
    {
        config(['app.url' => 'https://movaeduca.me']);

        $response = $this->get('http://www.movaeduca.me/api');

        $response->assertStatus(404);
    }

    /**
     * (C) El redirect web normal (no-API) sigue funcionando exactamente
     * igual después de añadir el guard de /api.
     */
    public function test_web_redirect_still_works_after_the_api_guard(): void
    {
        config(['app.url' => 'https://movaeduca.me']);

        $response = $this->get('http://www.movaeduca.me/marketplace?subject=fisica&page=3');

        $response->assertStatus(301);
        $response->assertRedirect('https://movaeduca.me/marketplace?subject=fisica&page=3');
    }
}
