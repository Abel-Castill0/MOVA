<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->account_closed_at && ! $request->routeIs(
            'account.closure.status', 'logout', 'legal.privacy', 'legal.terms', 'legal.refunds', 'complaints.*'
        )) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                abort(403, 'Tu cuenta está cerrada. Puedes consultar tu solicitud de eliminación.');
            }
            return redirect()->route('account.closure.status');
        }
        return $next($request);
    }
}
