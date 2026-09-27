<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AZ-3F: mientras config('seo.indexing_enabled') sea false (default fuera
 * del cutover a dominio final), toda respuesta lleva X-Robots-Tag para que
 * un buscador no indexe el FQDN temporal de staging aunque llegue a él por
 * un enlace, no solo vía robots.txt (ver routes/web.php).
 *
 * NON-CANONICAL HOST GUARD (dominio propio, pre-cutover): el FQDN generado
 * de Azure (*.azurecontainerapps.io) sigue siendo accesible aparte del
 * dominio propio — TrustHosts sigue deliberadamente desactivado (ver
 * app/Http/Kernel.php) para no arriesgar las sondas/health checks de Azure
 * justo antes del release, así que ese host no se puede simplemente
 * rechazar. En cambio, incluso con indexing_enabled=true, SOLO el hostname
 * de APP_URL puede quedar indexable — cualquier otro host (el FQDN de
 * Azure, un dominio con typo, etc.) recibe noindex igual, sin bloquear la
 * request ni devolver un código de error distinto (nunca afecta
 * /healthz/readyz: solo añade un header a su respuesta, nunca toca su
 * status/body). No se usa el Host de la request para CONSTRUIR ninguna URL
 * aquí ni en ningún otro sitio — solo se compara contra config('app.url'),
 * la única fuente de verdad para "cuál es nuestro host canónico".
 */
class PreventIndexingWhenDisabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('seo.indexing_enabled') || ! $this->isCanonicalHost($request)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        return $response;
    }

    /**
     * Fail-closed: sin un APP_URL con host parseable, ningún host cuenta
     * como canónico (nunca se asume indexable "por defecto").
     */
    private function isCanonicalHost(Request $request): bool
    {
        $canonicalHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($canonicalHost) || $canonicalHost === '') {
            return false;
        }

        return strcasecmp($request->getHost(), $canonicalHost) === 0;
    }
}
