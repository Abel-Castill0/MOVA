<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEO gap encontrado al auditar el estado real de MOVA: existía robots.txt
 * pero ningún sitemap.xml, y robots.txt no referenciaba ninguno. Ambos ahora
 * son rutas dinámicas (no archivos estáticos en public/), generadas desde
 * APP_URL en runtime.
 */
class SitemapTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitemap_lists_the_static_public_pages(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml');
        $response->assertSeeText(route('welcome'), false);
        $response->assertSeeText(route('marketplace'), false);
        $response->assertSeeText(route('legal.privacy'), false);
    }

    public function test_sitemap_lists_only_verified_teacher_profiles(): void
    {
        $verifiedTeacher = User::factory()->create();
        $verifiedTeacher->assignRole('teacher');
        $verified = TeacherProfile::create(['user_id' => $verifiedTeacher->id, 'is_verified' => true]);

        $unverifiedTeacher = User::factory()->create();
        $unverifiedTeacher->assignRole('teacher');
        $unverified = TeacherProfile::create(['user_id' => $unverifiedTeacher->id, 'is_verified' => false]);

        $response = $this->get('/sitemap.xml');

        $response->assertSeeText(route('teachers.show', $verified), false);
        // Un perfil no verificado da 404 en TeacherPublicController::show —
        // listarlo en el sitemap enviaría a los buscadores a una URL rota.
        $response->assertDontSeeText(route('teachers.show', $unverified), false);
    }

    public function test_robots_txt_references_the_sitemap_when_indexing_is_enabled(): void
    {
        config(['seo.indexing_enabled' => true]);

        $response = $this->get('/robots.txt');

        $response->assertOk();
        // Laravel 11 trae symfony/http-foundation 7, que cambió el charset
        // por defecto de Response::prepare() a minúsculas ('utf-8' en vez de
        // 'UTF-8', vendor/symfony/http-foundation/Response.php) — nuestra
        // ruta nunca fija el charset explícitamente, solo 'text/plain'.
        // Mismo valor semántico (el nombre de charset no distingue
        // mayúsculas en HTTP/MIME), no un cambio de comportamiento real.
        $response->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $response->assertSeeText('Sitemap: '.route('sitemap'), false);
        $response->assertSeeText('Disallow:', false);
    }

    // ── AZ-3F: bloqueo de indexación fuera del cutover a dominio final ────

    public function test_robots_txt_disallows_everything_by_default(): void
    {
        // seo.indexing_enabled=false es el default (config/seo.php) — no hace
        // falta fijarlo aquí a propósito, para que este test detecte si
        // alguna vez cambiara el default sin que fuera una decisión deliberada.
        $response = $this->get('/robots.txt');

        $response->assertOk();
        $response->assertSeeText('Disallow: /', false);
        $response->assertDontSeeText('Sitemap:', false);
    }

    public function test_robots_txt_allows_everything_when_indexing_is_enabled(): void
    {
        config(['seo.indexing_enabled' => true]);

        $response = $this->get('/robots.txt');

        $response->assertDontSeeText('Disallow: /', false);
    }

    public function test_x_robots_tag_header_is_present_when_indexing_is_disabled(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function test_x_robots_tag_header_is_absent_when_indexing_is_enabled(): void
    {
        config(['seo.indexing_enabled' => true]);

        $response = $this->get('/');

        $response->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_x_robots_tag_applies_to_every_route_not_only_robots_and_sitemap(): void
    {
        $response = $this->get('/marketplace');

        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    /**
     * NON-CANONICAL HOST GUARD (App\Http\Middleware\PreventIndexingWhenDisabled):
     * el FQDN generado de Azure (*.azurecontainerapps.io) sigue accesible
     * aparte del dominio propio — TrustHosts sigue desactivado a propósito
     * (no arriesgar health checks/sondas operacionales de Azure). Con
     * indexing habilitado, SOLO el host de APP_URL puede quedar indexable;
     * cualquier otro host recibe noindex igual, sin que la request se
     * bloquee (sigue 200).
     */
    public function test_x_robots_tag_is_absent_on_the_canonical_host_when_indexing_is_enabled(): void
    {
        config(['seo.indexing_enabled' => true, 'app.url' => 'http://localhost']);

        $response = $this->get('http://localhost/');

        $response->assertOk();
        $response->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_x_robots_tag_is_present_on_a_noncanonical_host_even_when_indexing_is_enabled(): void
    {
        config(['seo.indexing_enabled' => true, 'app.url' => 'https://staging.movaeduca.me']);

        $response = $this->get('http://mova-web.whitecliff-88cda913.mexicocentral.azurecontainerapps.io/');

        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function test_x_robots_tag_is_present_on_a_noncanonical_host_when_indexing_is_disabled(): void
    {
        config(['seo.indexing_enabled' => false, 'app.url' => 'https://staging.movaeduca.me']);

        $response = $this->get('http://mova-web.whitecliff-88cda913.mexicocentral.azurecontainerapps.io/');

        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function test_x_robots_tag_is_present_on_the_canonical_host_when_indexing_is_disabled(): void
    {
        config(['seo.indexing_enabled' => false, 'app.url' => 'https://staging.movaeduca.me']);

        $response = $this->get('https://staging.movaeduca.me/');

        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    /**
     * /healthz nunca debe verse afectado por este guard más allá del
     * header — su status/body de liveness siguen intactos incluso desde un
     * host no canónico (las sondas de Azure pegan al FQDN generado, no al
     * dominio propio).
     */
    public function test_healthz_still_responds_ok_from_a_noncanonical_host(): void
    {
        config(['seo.indexing_enabled' => true, 'app.url' => 'https://staging.movaeduca.me']);

        $response = $this->get('http://mova-web.whitecliff-88cda913.mexicocentral.azurecontainerapps.io/healthz');

        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
