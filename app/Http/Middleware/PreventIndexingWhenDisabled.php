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
 */
class PreventIndexingWhenDisabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('seo.indexing_enabled')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        return $response;
    }
}
