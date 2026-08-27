<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
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
                // P0 encontrado y corregido en esta pasada: el allow-list de
                // columnas iba antes como segundo argumento de paginate() —
                // ->paginate(24, ['id','user_id','bio','hourly_rate']) — y
                // NO se aplicaba. Verificado en vivo (json_encode() del
                // resultado real): con withAvg()/withCount() ya presentes en
                // el query builder, ese segundo argumento se ignora y
                // paginate() devuelve TODAS las columnas de la tabla —
                // yape_number, plin_number, referral_code, rejection_reason,
                // credits_available/reserved, todo — a un endpoint público
                // sin autenticación. Exactamente lo que el comentario
                // original de este método decía que nunca debía pasar.
                // ->select() explícito ANTES de with()/withAvg()/withCount()
                // sí se respeta (withAvg/withCount usan addSelect(), que no
                // pisa un select() ya fijado) — verificado con el mismo
                // json_encode() de control, sin este cambio.
                ->select(['id', 'user_id', 'bio', 'hourly_rate'])
                ->with(['user:id,name,avatar_url', 'subjects:id,name'])
                ->withAvg('visibleReviews as avg_rating', 'rating')
                ->withCount('visibleReviews as review_count')
                ->orderByDesc('review_count')
                ->paginate(24),

            // Mismos conteos que WelcomeController::index() (misma fuente de
            // verdad, sin duplicar la consulta de forma distinta) — refuerzan
            // confianza sin ser un filtro ni un buscador.
            'stats' => [
                'teachers' => TeacherProfile::where('is_verified', true)->count(),
                'completed' => Lesson::where('status', 'completed')->count(),
            ],
        ]);
    }
}
