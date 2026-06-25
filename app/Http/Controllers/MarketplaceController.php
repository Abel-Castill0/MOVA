<?php

namespace App\Http\Controllers;

use App\Models\ClassOffer;
use App\Models\Subject;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MarketplaceController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'subject_id' => 'nullable|integer|exists:subjects,id',
            'level'      => 'nullable|in:primaria,secundaria,universidad',
            'max_rate'   => 'nullable|numeric|min:0|max:9999',
            'search'     => 'nullable|string|max:100',
        ]);

        $query = ClassOffer::where('is_active', true)
            ->whereHas('teacherProfile', fn($q) => $q->where('is_verified', true))
            ->with(['teacherProfile.user', 'subject']);

        if ($request->subject_id) {
            $query->where('subject_id', $request->subject_id);
        }
        if ($request->level) {
            $query->whereHas('subject', fn($q) => $q->where('level', $request->level)->orWhere('level', 'todos'));
        }
        if ($request->max_rate) {
            $query->where(function ($q) use ($request) {
                $q->whereNotNull('specific_rate')->where('specific_rate', '<=', $request->max_rate)
                  ->orWhereNull('specific_rate')
                    ->whereHas('teacherProfile', fn($q2) => $q2->where('hourly_rate', '<=', $request->max_rate));
            });
        }
        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', $search)
                  ->orWhereHas('teacherProfile.user', fn($q2) => $q2->where('name', 'like', $search));
            });
        }

        return Inertia::render('Marketplace/Index', [
            'offers'   => $query->latest()->paginate(12)->withQueryString(),
            'subjects' => Subject::orderBy('name')->get(),
            'filters'  => $request->only(['subject_id', 'level', 'max_rate', 'search']),
        ]);
    }
}
