<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/dashboard';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Chatbot Movi: endpoint público (anónimos incluidos) que gasta cuota
        // de un proveedor externo. Límite por minuto, por usuario autenticado
        // o por IP (resuelta por TrustProxies: solo el valor que Azure añade,
        // nunca uno suministrado por el cliente). El techo GLOBAL diario vive
        // en ChatbotService::reply(), justo antes de llamar al proveedor, para
        // que peticiones inválidas no consuman la cuota compartida del día.
        RateLimiter::for('chatbot', function (Request $request) {
            return Limit::perMinute((int) config('chatbot.rate_limit_per_minute', 10))
                ->by('chatbot:'.($request->user()?->id ? 'u'.$request->user()->id : 'ip'.$request->ip()));
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
