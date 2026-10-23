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

    /**
     * Política de cambios, cancelaciones y devoluciones. Describe SOLO lo ya decidido y publicado (Términos §5 y §7,
     * docs/release/MOVA_V1_DECISIONS.md §3); el plazo sale de config/credits.php.
     */
    public function refunds()
    {
        return Inertia::render('Legal/Refunds', [
            'version' => (string) config('legal.versions.terms'),
            'refundDays' => (int) config('credits.refund_days', 7),
            'creditMinutes' => (int) config('credits.credit_minutes', 60),
            'creditPricePen' => (string) config('credits.credit_price_pen', '2.00'),
            'complaintResponseDays' => (int) config('legal.complaint_response_days', 15),
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
