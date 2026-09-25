<?php

namespace App\Http\Middleware;

use App\Services\AdminMfaService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * P0-C — MFA obligatorio para admin, aplicado server-side a cada ruta del
 * grupo autenticado (no solo a la UI).
 *
 *   admin.mfa            → admin enrolado y verificado en esta sesión.
 *   admin.mfa:sensitive  → además, verificación reciente (step-up) para
 *                          créditos, refunds, suspensiones, profesores.
 *
 * No-op para usuarios que no son admin.
 */
class EnsureAdminMfa
{
    public function __construct(private AdminMfaService $mfa) {}

    public function handle(Request $request, Closure $next, ?string $level = null): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole('admin')) {
            return $next($request);
        }

        if (! $this->mfa->isEnrolled($user)) {
            return $this->deny($request, 'admin.mfa.setup');
        }

        $elapsed = $this->mfa->secondsSinceVerified($request, $user);
        $maxAge = $level === 'sensitive'
            ? (int) config('mova_security.admin_mfa.step_up_minutes', 15) * 60
            : (int) config('mova_security.admin_mfa.session_minutes', 720) * 60;

        if ($elapsed === null || $elapsed > $maxAge) {
            return $this->deny($request, 'admin.mfa.challenge');
        }

        return $next($request);
    }

    private function deny(Request $request, string $route): Response
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(403, 'Se requiere verificación MFA de administrador.');
        }

        // Solo se recuerda el destino de navegaciones GET; un POST sensible
        // vuelve a la página de origen y el admin reenvía la acción.
        if ($request->isMethod('GET')) {
            $request->session()->put('url.intended', $request->fullUrl());
        } else {
            $request->session()->put('url.intended', url()->previous());
        }

        return $request->header('X-Inertia')
            ? \Inertia\Inertia::location(route($route))
            : redirect()->route($route);
    }
}
