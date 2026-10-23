<?php

namespace App\Http\Controllers;

use App\Support\Catalog;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Catálogo y compra PÚBLICOS (sin sesión). No cobra nada ni crea recargas: el cobro sigue siendo
 * `teacher.credits.checkout.store` (profesor autenticado, importe y créditos del catálogo del servidor).
 */
class CatalogController extends Controller
{
    public function index()
    {
        return Inertia::render('Catalog/Index', [
            'packages' => Catalog::packages(),
            'subjects' => Catalog::subjects(),
            'classRates' => Catalog::classRates(),
            'creditMinutes' => (int) config('credits.credit_minutes', 60),
            'creditPricePen' => (string) config('credits.credit_price_pen', '2.00'),
            'refundDays' => (int) config('credits.refund_days', 7),
        ]);
    }

    public function package(Request $request, string $package)
    {
        $item = Catalog::package($package);
        abort_if($item === null, 404);

        // Un invitado que elige un paquete vuelve aquí tras iniciar sesión o registrarse (conserva la selección).
        if (! $request->user()) {
            $request->session()->put('url.intended', $request->url());
        }

        $user = $request->user();

        return Inertia::render('Catalog/Package', [
            'package' => $item,
            'viewer' => [
                'authenticated' => $user !== null,
                'isTeacher' => $user?->hasRole('teacher') ?? false,
                'role' => $user?->getRoleNames()->first(),
            ],
            'refundDays' => (int) config('credits.refund_days', 7),
            'creditMinutes' => (int) config('credits.credit_minutes', 60),
            'otherPackages' => collect(Catalog::packages())->reject(fn ($p) => $p['code'] === $item['code'])->values()->all(),
        ]);
    }
}
