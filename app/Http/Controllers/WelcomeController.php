<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\TeacherReview;
use App\Models\User;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

class WelcomeController extends Controller
{
    public function index()
    {
        return Inertia::render('Welcome', [
            'subjects' => Subject::orderBy('name')->get(['id', 'name', 'level']),

            'featuredTeachers' => TeacherProfile::where('is_verified', true)
                ->with(['user:id,name', 'subjects:id,name'])
                ->withCount('classes')
                ->withAvg('visibleReviews as avg_rating', 'rating')
                ->orderByDesc('classes_count')
                ->limit(6)
                ->get(['id', 'user_id', 'bio', 'hourly_rate']),

            'stats' => [
                'teachers'  => TeacherProfile::where('is_verified', true)->count(),
                'students'  => Role::where('name', 'parent')->exists()
                               ? User::role('parent')->count()
                               : 0,
                'completed' => Lesson::where('status', 'completed')->count(),
            ],

            'testimonials' => TeacherReview::where('is_visible', true)
                ->where('rating', 5)
                ->whereNotNull('comment')
                ->where('comment', '!=', '')
                // qa/tests/flujo-completo.spec.js marca cada reseña de prueba con este
                // prefijo para verificar idempotencia — nunca debe aparecer en una
                // reseña real, así que lo excluimos por si acaso corre en local.
                ->where('comment', 'not like', 'E2E-PLAYWRIGHT-%')
                ->with('parent:id,name')
                ->latest()
                ->limit(3)
                ->get()
                ->map(fn (TeacherReview $review) => [
                    'name'  => $review->parent?->name ?? 'Familia MOVA',
                    'quote' => $review->comment,
                ]),
        ]);
    }
}
