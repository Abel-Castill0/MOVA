<?php

namespace App\Http\Middleware;

use App\Models\LegalAcceptance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * C-P1-LEGAL-REACCEPTANCE — si cambia la versión vigente de Términos o
 * Privacidad (config/legal.php), el usuario debe aceptarla antes de seguir
 * navegando. Decisiones deliberadas:
 *
 *  - Intercepta navegaciones y mutaciones normales. Una petición POST directa
 *    no puede crear datos de un menor ni aceptar clases con términos vencidos.
 *    Conserva las consultas JSON y las operaciones de un checkout ya iniciado
 *    para no dejar un pago a medias.
 *  - Admins exentos: son el equipo de MOVA, no usuarios del servicio, y no
 *    se les debe bloquear la operación (incidentes, reclamos) por esto.
 *  - Las rutas de aceptación y las páginas legales viven FUERA del grupo que
 *    lleva este middleware (routes/web.php): no hay bucle de redirección y
 *    el usuario puede leer los documentos antes de aceptar.
 */
class EnsureCurrentLegalAcceptance
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user
            || $user->hasRole('admin')
            || LegalAcceptance::hasAcceptedCurrent($user)) {
            return $next($request);
        }

        if (($request->isMethod('GET') && $request->expectsJson())
            || $request->routeIs(
                'teacher.credits.checkout.show',
                'teacher.credits.checkout.status',
                'teacher.credits.checkout.pay',
                'teacher.credits.checkout.refresh',
            )) {
            return $next($request);
        }

        if ($request->isMethod('GET')) {
            redirect()->setIntendedUrl($request->fullUrl());
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Debes aceptar los documentos legales vigentes para continuar.'], 409);
        }

        return redirect()->route('legal.accept');
    }
}
