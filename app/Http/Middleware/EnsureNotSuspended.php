<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && $request->user()->suspended_at !== null) {
            if ($request->expectsJson() || $request->header('X-Inertia')) {
                return redirect()->route('suspended');
            }
            return redirect()->route('suspended');
        }

        return $next($request);
    }
}
