<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustProxies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

/**
 * TRUST BOUNDARY REGRESSION (Azure Container Apps forwarded headers) — a
 * live staging probe proved that a request to the Azure-generated FQDN
 * carrying a client-supplied `X-Forwarded-Host: www.staging.movaeduca.me`
 * produced a real 301 from RedirectWwwToApex: ACA passes that header
 * through unsanitized, and the previous config (`$proxies = '*'` +
 * HEADER_X_FORWARDED_HOST) trusted it. These tests drive the REAL
 * App\Http\Middleware\TrustProxies (not a reimplementation) with a
 * REMOTE_ADDR standing in for Azure's own ingress proxy, to prove the
 * fixed trust model:
 *   - X-Forwarded-Proto IS trusted (ACA overwrites it to the true
 *     external scheme — needed for isSecure()/forceScheme('https')).
 *   - X-Forwarded-For IS trusted, but only the immediate proxy
 *     (REMOTE_ADDR) is a trusted hop, so Symfony resolves the client IP
 *     as the rightmost entry ACA itself appended — never a client-
 *     supplied leftmost value.
 *   - X-Forwarded-Host is NEVER trusted, regardless of what's sent.
 *
 * RATE LIMITERS (read-only review, no redesign): RouteServiceProvider's
 * 'api' limiter keys explicitly on $request->ip(); every throttle:N,1 in
 * routes/auth.php (login, register, password reset, 2FA) uses Laravel's
 * default ThrottleRequests resolver, which also keys on $request->ip().
 * Under the OLD '*' trust config, EVERY hop in an attacker-supplied
 * X-Forwarded-For chain was trusted, so an attacker could set an
 * arbitrary X-Forwarded-For value and have Symfony treat it as the real
 * client IP — trivially rotating it per request to evade every one of
 * those IP-keyed throttles (e.g. login brute force). With only REMOTE_ADDR
 * trusted, the client-supplied portion of the chain is never taken as
 * truth; only the value Azure itself appends is, so those limiters key on
 * the real requester again.
 */
class TrustProxiesTest extends TestCase
{
    use RefreshDatabase;

    private const AZURE_FQDN = 'mova-web.whitecliff-88cda913.mexicocentral.azurecontainerapps.io';

    /** Builds a request as if it had already reached this container behind Azure's ingress proxy. */
    private function requestBehindProxy(string $host, array $forwardedHeaders, string $remoteAddr = '10.0.0.10'): Request
    {
        $server = ['REMOTE_ADDR' => $remoteAddr];
        foreach ($forwardedHeaders as $name => $value) {
            $server['HTTP_'.str_replace('-', '_', strtoupper($name))] = $value;
        }

        return Request::create('http://'.$host.'/healthz', 'GET', [], [], [], $server);
    }

    private function passThrough(Request $request): Request
    {
        (new TrustProxies())->handle($request, fn ($req) => new Response('ok'));

        return $request;
    }

    public function test_x_forwarded_host_is_never_trusted_even_from_a_trusted_proxy(): void
    {
        $request = $this->passThrough($this->requestBehindProxy(self::AZURE_FQDN, [
            'X-Forwarded-Host' => 'www.staging.movaeduca.me',
            'X-Forwarded-Proto' => 'https',
        ]));

        $this->assertSame(self::AZURE_FQDN, $request->getHost());
        $this->assertNotSame('www.staging.movaeduca.me', $request->getHost());
        $this->assertTrue($request->isSecure(), 'X-Forwarded-Proto must remain trusted even though X-Forwarded-Host is not.');
    }

    public function test_x_forwarded_for_chain_resolves_to_the_azure_appended_ip_not_the_client_supplied_one(): void
    {
        // 203.0.113.66 = value a client could have supplied; 198.51.100.22
        // = the value ACA itself appends (documented ACA behavior: it
        // appends to whatever the client sent, it never replaces it).
        $request = $this->passThrough($this->requestBehindProxy(self::AZURE_FQDN, [
            'X-Forwarded-For' => '203.0.113.66, 198.51.100.22',
        ], remoteAddr: '10.0.0.10'));

        $this->assertSame('198.51.100.22', $request->ip());
        $this->assertNotSame('203.0.113.66', $request->ip());
    }

    public function test_redirect_spoof_regression_azure_fqdn_with_spoofed_forwarded_host_is_never_canonicalized(): void
    {
        config(['app.url' => 'https://staging.movaeduca.me']);

        $response = $this->call('GET', 'http://'.self::AZURE_FQDN.'/', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.10',
            'HTTP_X_FORWARDED_HOST' => 'www.staging.movaeduca.me',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $response->assertStatus(200);
        $response->assertHeaderMissing('Location');
    }

    public function test_seo_spoof_regression_spoofed_forwarded_host_never_makes_the_azure_fqdn_look_canonical(): void
    {
        config(['seo.indexing_enabled' => true, 'app.url' => 'https://staging.movaeduca.me']);

        $response = $this->call('GET', 'http://'.self::AZURE_FQDN.'/', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.10',
            'HTTP_X_FORWARDED_HOST' => 'staging.movaeduca.me',
        ]);

        $response->assertStatus(200);
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function test_normal_custom_domain_traffic_is_unaffected(): void
    {
        $request = $this->passThrough($this->requestBehindProxy('staging.movaeduca.me', [
            'X-Forwarded-Proto' => 'https',
        ]));

        $this->assertSame('staging.movaeduca.me', $request->getHost());
        $this->assertTrue($request->isSecure());
    }
}
