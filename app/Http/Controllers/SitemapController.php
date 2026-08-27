<?php

namespace App\Http\Controllers;

use App\Models\TeacherProfile;
use Illuminate\Http\Response;

/**
 * sitemap.xml dinámico — gap real encontrado al auditar el estado SEO de
 * MOVA (no existía ninguno; `robots.txt` sí). Generado, no estático: las
 * URLs de perfiles públicos de profesor solo tienen sentido para perfiles
 * verificados (mismo gate que `TeacherPublicController::show` — un sitemap
 * que listara perfiles no verificados apuntaría a URLs que devuelven 404).
 */
class SitemapController extends Controller
{
    public function index(): Response
    {
        $staticUrls = [
            ['loc' => route('welcome'), 'changefreq' => 'weekly', 'priority' => '1.0'],
            ['loc' => route('marketplace'), 'changefreq' => 'daily', 'priority' => '0.9'],
            ['loc' => route('about'), 'changefreq' => 'monthly', 'priority' => '0.5'],
            ['loc' => route('landing.teacher'), 'changefreq' => 'monthly', 'priority' => '0.6'],
            ['loc' => route('landing.student'), 'changefreq' => 'monthly', 'priority' => '0.6'],
            ['loc' => route('legal.terms'), 'changefreq' => 'yearly', 'priority' => '0.2'],
            ['loc' => route('legal.privacy'), 'changefreq' => 'yearly', 'priority' => '0.2'],
        ];

        // Solo perfiles verificados: son los únicos que TeacherPublicController
        // realmente sirve (abort_unless is_verified, 404) — listar el resto
        // enviaría a los buscadores a URLs que no resuelven.
        $teacherUrls = TeacherProfile::where('is_verified', true)
            ->orderByDesc('updated_at')
            ->get(['id', 'updated_at'])
            ->map(fn (TeacherProfile $profile) => [
                'loc' => route('teachers.show', $profile),
                'lastmod' => $profile->updated_at?->toAtomString(),
                'changefreq' => 'weekly',
                'priority' => '0.7',
            ]);

        $urls = collect($staticUrls)->concat($teacherUrls);

        $xml = view('sitemap', ['urls' => $urls])->render();

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }
}
