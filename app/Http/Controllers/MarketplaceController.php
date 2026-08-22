<?php

namespace App\Http\Controllers;

use App\Models\TeacherProfile;
use Inertia\Inertia;

class MarketplaceController extends Controller
{
    /**
     * Vista puramente informativa: lista de profesores verificados. El
     * padre NO elige ni contacta a un profesor específico desde aquí — solo
     * conoce quién enseña en MOVA. Solicitar una clase pasa siempre por el
     * formulario abierto (ClassRequestController::create), sin profesor
     * preseleccionado; el único atajo directo a un profesor es el código
     * de referido, que se consigue fuera de esta pantalla (ver
     * TeacherPublicController::show() y JitsiModal.vue).
     *
     * Antes esta vista listaba ClassOffer (con filtros de materia/nivel/
     * precio y botones "Solicitar clase" por oferta) — eso es exactamente
     * el "elegir profesor directamente" que se elimina aquí. ClassOffer
     * sigue existiendo sin cambios (el profesor lo sigue usando para fijar
     * su tarifa específica y sus cupos de mentoría); solo deja de ser la
     * fuente de esta pantalla pública.
     */
    public function index()
    {
        return Inertia::render('Marketplace/Index', [
            'teachers' => TeacherProfile::where('is_verified', true)
                ->with(['user:id,name,avatar_url', 'subjects:id,name'])
                ->withAvg('visibleReviews as avg_rating', 'rating')
                ->withCount('visibleReviews as review_count')
                ->orderByDesc('review_count')
                // Columnas explícitas, sin 'referral_code': esta vista es
                // pública y no requiere autenticación — el código nunca
                // debe llegar a este payload.
                ->paginate(24, ['id', 'user_id', 'bio', 'hourly_rate']),
        ]);
    }
}
