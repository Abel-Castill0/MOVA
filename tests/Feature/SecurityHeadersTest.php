<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_baseline_security_headers_on_pages_and_probes(): void
    {
        foreach (['/', '/healthz', '/login'] as $uri) {
            $this->get($uri)
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
                ->assertHeader('Content-Security-Policy', "base-uri 'self'; object-src 'none'; frame-ancestors 'self'");
            $this->assertMatchesRegularExpression(
                "/^script-src 'self' 'nonce-[A-Za-z0-9]{20,}'; script-src-attr 'none'$/",
                (string) $this->get($uri)->headers->get('Content-Security-Policy-Report-Only')
            );
        }
    }

    public function test_every_inline_script_on_a_page_carries_the_nonce_of_the_policy(): void
    {
        $response = $this->get('/login');
        preg_match("/'nonce-([A-Za-z0-9]+)'/", (string) $response->headers->get('Content-Security-Policy-Report-Only'), $m);
        $this->assertNotEmpty($m[1] ?? null, 'La política no lleva nonce.');

        preg_match_all('/<script(?![^>]*src=)([^>]*)>/i', $response->getContent(), $inline);
        $this->assertNotEmpty($inline[1], 'Se esperaba al menos un script en línea (tema y rutas).');
        foreach ($inline[1] as $attrs) {
            if (str_contains($attrs, 'application/json')) {
                continue; // datos, no código
            }
            $this->assertStringContainsString('nonce="'.$m[1].'"', $attrs);
        }
    }

    public function test_the_nonce_changes_on_every_request(): void
    {
        $a = $this->get('/login')->headers->get('Content-Security-Policy-Report-Only');
        $b = $this->get('/login')->headers->get('Content-Security-Policy-Report-Only');
        $this->assertNotSame($a, $b);
    }

    public function test_hsts_only_in_production_over_https(): void
    {
        $this->get('/healthz')->assertHeaderMissing('Strict-Transport-Security');

        $this->app['env'] = 'production';
        $this->get('https://localhost/healthz')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
