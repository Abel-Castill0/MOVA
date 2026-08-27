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

            // P0 encontrado y corregido junto con el mismo bug en
            // MarketplaceController::index() (ver docs/MOVA_DESIGN_AUDIT_FINAL.md):
            // el allow-list de columnas iba como argumento de get() — igual
            // que paginate(), get($columns) tampoco es determinista cuando
            // el query ya tiene withCount()/withAvg() encadenados. Verificado
            // en vivo con json_encode(): la home pública devolvía yape_number,
            // plin_number, referral_code, rejection_reason y reviewed_by de
            // cada profesor destacado, en la ruta de MAYOR tráfico de todo
            // MOVA. `user_id` se mantiene en el ->select() (Eloquent lo
            // necesita para resolver el `belongsTo` de `user`), pero nunca
            // llega al frontend — está en TeacherProfile::$hidden.
            'featuredTeachers' => TeacherProfile::where('is_verified', true)
                ->select(['id', 'user_id', 'bio', 'hourly_rate'])
                ->with(['user:id,name', 'subjects:id,name'])
                ->withCount('classes')
                ->withAvg('visibleReviews as avg_rating', 'rating')
                ->orderByDesc('classes_count')
                ->limit(6)
                ->get(),

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
