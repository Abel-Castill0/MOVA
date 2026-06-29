<?php

namespace App\Http\Controllers;

use App\Models\AiUsageLog;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AiUsageController extends Controller
{
    public function index()
    {
        $today = today();
        $monthStart = now()->startOfMonth();

        $statsToday = AiUsageLog::whereDate('created_at', $today)
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
