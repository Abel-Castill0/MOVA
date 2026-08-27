<?php

namespace App\Http\Controllers;

use App\Models\AiUsageLog;
use App\Support\LimaClock;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AiUsageController extends Controller
{
    public function index()
    {
        $monthStart = now()->startOfMonth();

        // BUG-5: "hoy" para un admin en Perú, no medianoche UTC — ver LimaClock.
        [$todayStart, $todayEnd] = LimaClock::todayRangeUtc();

        $statsToday = AiUsageLog::where('created_at', '>=', $todayStart)
            ->where('created_at', '<', $todayEnd)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $statsMonth = AiUsageLog::where('created_at', '>=', $monthStart)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $recent = AiUsageLog::latest('created_at')
            ->take(30)
            ->get(['id', 'provider', 'model', 'status', 'total_tokens', 'error_type', 'created_at']);

        $limits = [
            'daily'   => (int) config('diagnostic.daily_limit', 50),
            'monthly' => (int) config('diagnostic.monthly_limit', 500),
            'enabled' => (bool) config('diagnostic.ai_enabled', false),
        ];

        return Inertia::render('Admin/AiUsage', [
            'stats_today' => $statsToday,
            'stats_month' => $statsMonth,
            'recent'      => $recent,
            'limits'      => $limits,
        ]);
    }
}
