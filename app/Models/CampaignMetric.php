<?php

namespace App\Models;

use App\Support\RequestSlots;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Contador diario (hora de Lima) de la campaña física con QR. Agregado puro: sin datos personales.
 * El incremento es atómico (INSERT … ON DUPLICATE KEY / ON CONFLICT vía upsert con expresión), seguro con visitas simultáneas.
 */
class CampaignMetric extends Model
{
    public const CAMPAIGNS = ['profe', 'familias'];

    public const EVENTS = ['visit', 'play', 'cta'];

    protected $fillable = ['day', 'campaign', 'event', 'count'];

    public static function bump(string $campaign, string $event): void
    {
        if (! in_array($campaign, self::CAMPAIGNS, true) || ! in_array($event, self::EVENTS, true)) {
            return;
        }
        $now = now();
        DB::table('campaign_metrics')->upsert(
            [['day' => RequestSlots::today($now), 'campaign' => $campaign, 'event' => $event, 'count' => 1, 'created_at' => $now, 'updated_at' => $now]],
            ['day', 'campaign', 'event'],
            ['count' => DB::raw('campaign_metrics.count + 1'), 'updated_at' => $now],
        );
    }
}
