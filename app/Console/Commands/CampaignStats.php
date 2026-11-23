<?php

namespace App\Console\Commands;

use App\Models\CampaignMetric;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Resumen de la campaña física con QR: visitas, reproducciones y clics en «Empieza ahora», por página. */
class CampaignStats extends Command
{
    protected $signature = 'mova:campaign-stats {--days=30 : Días hacia atrás (hora de Lima)}';

    protected $description = 'Muestra visitas, reproducciones y clics de /profe y /familias';

    public function handle(): int
    {
        $since = now('America/Lima')->subDays(max(1, (int) $this->option('days')) - 1)->format('Y-m-d');
        $rows = DB::table('campaign_metrics')->where('day', '>=', $since)
            ->selectRaw('campaign, event, SUM(count) as total')->groupBy('campaign', 'event')->get();

        $table = [];
        foreach (CampaignMetric::CAMPAIGNS as $campaign) {
            $get = fn (string $event) => (int) ($rows->first(fn ($r) => $r->campaign === $campaign && $r->event === $event)->total ?? 0);
            [$visits, $plays, $cta] = [$get('visit'), $get('play'), $get('cta')];
            $table[] = ['/'.$campaign, $visits, $plays, $cta, $visits > 0 ? round(100 * $cta / $visits, 1).' %' : '—'];
        }

        $this->info("Campaña QR desde {$since} (hora de Lima)");
        $this->table(['Página', 'Visitas', 'Play', 'Empieza ahora', 'Clic / visita'], $table);

        return self::SUCCESS;
    }
}
