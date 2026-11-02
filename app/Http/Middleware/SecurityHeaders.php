<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad de base. La CSP es deliberadamente acotada a
 * directivas que no dependen del inventario de scripts/iframes externos
 * (JaaS, Mercado Pago/3DS, Cloudinary, Sentry): anti-clickjacking,
 * base-uri y object-src. Endurecer script-src exige primero un inventario
 * en Report-Only para no romper pagos o videollamadas.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff', false);
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $headers->set('Permissions-Policy', 'geolocation=(), usb=(), magnetometer=(), gyroscope=()', false);

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', "base-uri 'self'; object-src 'none'; frame-ancestors 'self'");
        }

        // Primer paso hacia script-src: evaluar en Report-Only la diferencia
        // entre código propio y scripts embebidos/externos (Culqi 3DS, JaaS,
        // Vite) sin bloquear el checkout ni las videollamadas existentes.
        // Activar una CSP ejecutable exige recolectar y validar los reportes
        // en staging, construir la allowlist real y pasar regresión E2E.
        if (! $headers->has('Content-Security-Policy-Report-Only')) {
            $headers->set('Content-Security-Policy-Report-Only', "script-src 'self'; script-src-attr 'none'");
        }

        if (app()->environment('production') && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains', false);
        }

        return $response;
    }
}
