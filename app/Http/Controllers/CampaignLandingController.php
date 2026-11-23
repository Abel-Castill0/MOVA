<?php

namespace App\Http\Controllers;

use App\Models\CampaignMetric;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * Páginas de destino de la campaña física con QR: /profe (tarjetas para profesores) y /familias (volantes para padres).
 * Un video, un botón. «Empieza ahora» pasa por el servidor (/profe/empezar) para contar el clic de forma fiable y lleva a
 * la página principal con los UTM de la campaña. La medición es un contador diario agregado (CampaignMetric).
 */
class CampaignLandingController extends Controller
{
    /** Datos fijos de cada campaña (no vienen del usuario). */
    private const CAMPAIGNS = [
        'profe' => [
            'audience' => 'teacher',
            'title' => 'Enseña con MOVA – Profesores',
            'description' => 'Profe, tu conocimiento tiene valor. Mira el video y empieza a recibir solicitudes de familias en MOVA.',
            'video' => '/media/campana/profe.mp4',
            'poster' => '/media/campana/profe-poster.jpg',
            'captions' => '/media/campana/profe.vtt',
            'utm' => ['utm_source' => 'qr_tarjeta', 'utm_medium' => 'offline', 'utm_campaign' => 'profesores'],
        ],
        'familias' => [
            'audience' => 'parent',
            'title' => 'Clases particulares en vivo – Familias MOVA',
            'description' => 'Tu hijo no tiene que enfrentarlo solo. Mira el video y solicita una clase con profesores verificados en MOVA.',
            'video' => '/media/campana/familias.mp4',
            'poster' => '/media/campana/familias-poster.jpg',
            'captions' => '/media/campana/familias.vtt',
            'utm' => ['utm_source' => 'qr_volante', 'utm_medium' => 'offline', 'utm_campaign' => 'padres'],
        ],
    ];

    public function show(Request $request, string $campaign)
    {
        $config = self::CAMPAIGNS[$campaign];
        if ($this->countable($request)) {
            $this->bump($campaign, 'visit');
        }

        return Inertia::render('Landing/QrCampaign', [
            'campaign' => $campaign,
            'audience' => $config['audience'],
            'title' => $config['title'],
            'description' => $config['description'],
            'video' => $config['video'],
            'poster' => $config['poster'],
            'captions' => $config['captions'],
            'startUrl' => route('campaign.start', $campaign),
        ]);
    }

    /** «Empieza ahora»: cuenta el clic y lleva a la página principal con los UTM de esta campaña. */
    public function start(Request $request, string $campaign)
    {
        if ($this->countable($request)) {
            $this->bump($campaign, 'cta');
        }

        // away(): conserva el orden de los UTM tal cual (source, medium, campaign); el destino es siempre la portada propia.
        return redirect()->away(url('/').'/?'.http_build_query(self::CAMPAIGNS[$campaign]['utm']));
    }

    /** Primer «Play» de la visita (lo envía la página una sola vez). Sin cuerpo útil: 204. */
    public function event(Request $request, string $campaign)
    {
        $request->validate(['event' => ['required', 'in:play']]);
        if ($this->countable($request)) {
            $this->bump($campaign, 'play');
        }

        return response()->noContent();
    }

    /** Ni bots ni vistas previas de enlaces (WhatsApp, Facebook…) ni prefetch: solo personas abriendo la página. */
    private function countable(Request $request): bool
    {
        $agent = strtolower((string) $request->userAgent());
        $prefetch = strtolower((string) ($request->header('Sec-Purpose') ?? $request->header('Purpose') ?? ''));

        return $agent !== ''
            && ! str_contains($prefetch, 'prefetch')
            && ! preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp|telegram|curl|wget|python|headless/', $agent);
    }

    /** Medir nunca debe romper la página: un fallo se registra y se sigue. */
    private function bump(string $campaign, string $event): void
    {
        try {
            CampaignMetric::bump($campaign, $event);
        } catch (\Throwable $e) {
            Log::warning('CAMPAIGN_METRIC_FAILED', ['campaign' => $campaign, 'event' => $event, 'error' => $e->getMessage()]);
        }
    }
}
