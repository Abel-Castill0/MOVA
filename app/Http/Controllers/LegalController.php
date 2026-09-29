<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class LegalController extends Controller
{
    public function terms()
    {
        return Inertia::render('Legal/Terms', [
            'version' => (string) config('legal.versions.terms'),
        ]);
    }

    public function privacy()
    {
        // Datos del responsable desde config/legal.php: si faltan se muestran
        // como "pendiente" (no se inventan) y mova:health-check lo marca.
        return Inertia::render('Legal/Privacy', [
            'version'  => (string) config('legal.versions.privacy'),
            'provider' => [
                'business_name' => config('legal.provider.business_name'),
                'ruc'           => config('legal.provider.ruc'),
                'address'       => config('legal.provider.address'),
            ],
        ]);
    }
}
