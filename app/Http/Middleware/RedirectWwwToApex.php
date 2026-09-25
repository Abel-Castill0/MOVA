<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * PRODUCTION CUTOVER PREP (movaeduca.me): redirige exclusivamente
 * `www.<host(APP_URL)>` -> `https://<host(APP_URL)>`, preservando path y
 * query string. Ámbito deliberadamente estrecho — solo compara contra el
 * host de APP_URL, nunca contra un dominio hardcodeado:
 *
 *   - En producción (APP_URL=https://movaeduca.me), esto redirige
 *     www.movaeduca.me -> movaeduca.me, que es el objetivo.
 *   - En staging (APP_URL=https://staging.movaeduca.me), el único host que
 *     dispara esto es www.staging.movaeduca.me — que no existe en DNS y
 *     nadie visita, así que staging queda intacto SIN necesitar un caso
 *     especial que lo excluya.
 *   - El FQDN de Azure (*.azurecontainerapps.io) nunca empieza con "www.",
 *     así que tampoco puede coincidir.
 *
 * Nunca hay loop: tras el redirect el Host de la siguiente request ya no
 * es "www.<apex>", así que el guard no vuelve a dispararse. Solo
 * GET/HEAD — un POST (incluidos webhooks de Mercado Pago) nunca coincide,
 * así que API/webhooks quedan intactos sin necesitar excluirlos por ruta.
 */
class RedirectWwwToApex
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $next($request);
        }

        $apexHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($apexHost) || $apexHost === '') {
            return $next($request);
        }

        if (strcasecmp($request->getHost(), 'www.'.$apexHost) !== 0) {
            return $next($request);
        }

        // getRequestUri() conserva path + query string tal cual llegaron —
        // nunca se reconstruye la URL a partir de datos del Host (esta
        // comparación ya validó el Host contra config('app.url'), no al
        // revés).
        return new RedirectResponse('https://'.$apexHost.$request->getRequestUri(), 301);
    }
}
