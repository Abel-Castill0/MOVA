<?php

namespace App\Http\Controllers;

use App\Events\ClassRequestCreated;
use App\Models\ClassOffer;
use App\Models\ClassRequest;
use App\Models\DiagnosticRecommendation;
use App\Models\StudentDiagnostic;
use App\Models\Subject;
use App\Services\DiagnosticAiEnrichmentService;
use App\Services\DiagnosticRecommendationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DiagnosticsController extends Controller
{
    public function create()
    {
        $user = auth()->user();

        return Inertia::render('Diagnostics/Create', [
            'students' => $user->students()->get(['id', 'first_name', 'last_name', 'grade_level']),
            'subjects' => Subject::orderBy('name')->get(['id', 'name', 'level']),
        ]);
    }

    public function store(
        Request $request,
        DiagnosticRecommendationService $service,
        DiagnosticAiEnrichmentService $aiService
    ) {
        $user = auth()->user();

        $data = $request->validate([
            'student_id'      => 'required|exists:students,id',
            'subject_id'      => 'required|exists:subjects,id',
            'difficulty_text' => 'required|string|min:10|max:500',
            'school_feedback' => 'nullable|string|max:500',
            'goal'            => 'required|in:prepare_exam,solve_homework,continuous_support',
            'urgency'         => 'required|in:today_or_tomorrow,this_week,flexible',
        ]);

        $student = $user->students()->findOrFail($data['student_id']);

        // BUG-2 (docs/MOVA_AUDIT_PHASE0.md, sección Q) — este era el único
        // flujo de creación de datos reales sin ningún guard de idempotencia:
        // un doble-click o un reintento de red creaba dos diagnósticos y dos
        // solicitudes de clase. La clave es un hash del CONTENIDO real (no
        // aleatoria, no acotada por tiempo): el mismo padre reenviando el
        // mismo diagnóstico para el mismo alumno es el mismo diagnóstico;
        // un texto distinto genera una clave distinta y crea uno legítimo.
        $idempotencyKey = hash('sha256', implode('|', [
            $user->id, $student->id, $data['subject_id'], $data['difficulty_text'],
            $data['goal'], $data['urgency'],
        ]));

        $isNewDiagnostic = false;

        $diagnostic = DB::transaction(function () use ($data, $user, $student, $idempotencyKey, &$isNewDiagnostic) {
            try {
                $diagnostic = StudentDiagnostic::create(array_merge($data, [
                    'parent_user_id'  => $user->id,
                    'level'           => $student->grade_level,
                    'idempotency_key' => $idempotencyKey,
                ]));
                $isNewDiagnostic = true;

                return $diagnostic;
            } catch (UniqueConstraintViolationException) {
                // Ya existe un diagnóstico idéntico — no crear un segundo.
                return StudentDiagnostic::where('idempotency_key', $idempotencyKey)->lockForUpdate()->firstOrFail();
            }
        });

        // Fuera de la transacción: enrich() llama a un proveedor de IA
        // externo por HTTP, no debe mantener un lock de BD abierto durante
        // esa llamada. Solo se enriquece si el diagnóstico es nuevo en esta
        // request — el camino idempotente no debe volver a llamar a la IA.
        if ($isNewDiagnostic) {
            $aiService->enrich($diagnostic);
            $service->compute($diagnostic->fresh());
        }

        $classRequest = DB::transaction(function () use ($diagnostic, $student, $user) {
            $existing = $diagnostic->classRequest()->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            $classRequest = ClassRequest::create([
                'student_id'            => $student->id,
                'subject_id'            => $diagnostic->subject_id,
                'class_offer_id'        => null,
                'is_mentorship'         => $diagnostic->goal === 'continuous_support',
                'help_needed'           => $this->buildHelpNeeded($diagnostic->fresh()),
                'status'                => $user->parental_control ? 'pending_parent_approval' : 'open',
                'student_diagnostic_id' => $diagnostic->id,
            ]);

            $diagnostic->update(['status' => 'converted']);

            return $classRequest;
        });

        // El evento solo se dispara cuando la solicitud es realmente nueva en
        // esta request — no en el camino idempotente de "ya existía".
        if ($classRequest->wasRecentlyCreated) {
            event(new ClassRequestCreated($classRequest));
        }

        return redirect()->route('class-requests.index')
            ->with('success', 'Diagnóstico completado. Enviamos una solicitud genérica a los profesores verificados de la materia.');
    }

    /**
     * H-08 — Las recomendaciones ahora LLEGAN a la pantalla.
     *
     * `DiagnosticRecommendationService::compute()` calculaba y persistía hasta 5
     * recomendaciones con su ranking y sus razones... y esta acción devolvía
     * `'recommendations' => []` fijo, sobre una vista que ni siquiera declaraba
     * esa prop (docs/MOVA_SYSTEM_MAP.md H-08). Trabajo hecho y tirado a la
     * basura en cada diagnóstico.
     *
     * Tampoco se pasaba ya `subjects`: era una consulta a toda la tabla de
     * materias en cada carga para una prop que la vista no declaraba.
     */
    public function results(StudentDiagnostic $diagnostic)
    {
        $this->authorize('view', $diagnostic);

        return Inertia::render('Diagnostics/Results', [
            'diagnostic' => [
                'id' => $diagnostic->id,
                'goal' => $diagnostic->goal,
                'urgency' => $diagnostic->urgency,
                'subject' => $diagnostic->subject?->name,
                'student' => $diagnostic->student?->first_name,
                'ai_summary' => ($diagnostic->ai_summary && ($diagnostic->ai_confidence ?? 0) >= 60)
                    ? $diagnostic->ai_summary
                    : null,
            ],
            'recommendations' => $this->presentableRecommendations($diagnostic),
        ]);
    }

    /**
     * Las recomendaciones guardadas, revalidadas y traducidas a algo que un
     * padre pueda leer.
     *
     * NO SE RECALCULA NADA. El ranking se computó una sola vez, al crear el
     * diagnóstico; volver a puntuar en cada visita cambiaría el orden bajo los
     * pies del usuario y multiplicaría el coste de una pantalla de solo lectura.
     *
     * PERO SÍ SE REVALIDA LA ELEGIBILIDAD, porque el ranking es una foto y el
     * mundo se mueve: entre que se calculó y que el padre entra, un profesor
     * pudo ser rechazado por un admin, suspendido, o haber desactivado su
     * oferta. Recomendar a un profesor suspendido sería peor que no recomendar
     * a nadie — se filtran aquí, en la lectura, sin tocar las filas guardadas
     * (que siguen siendo el registro de qué se calculó y por qué).
     *
     * @return array<int, array<string, mixed>>
     */
    private function presentableRecommendations(StudentDiagnostic $diagnostic): array
    {
        return $diagnostic->recommendations()
            ->with([
                'classOffer.subject:id,name',
                'classOffer.teacherProfile:id,user_id,bio,hourly_rate,is_verified',
                'classOffer.teacherProfile.user:id,name,avatar_url,suspended_at',
            ])
            ->get()
            ->filter(function (DiagnosticRecommendation $recommendation) {
                $offer = $recommendation->classOffer;
                $profile = $offer?->teacherProfile;

                return $offer?->is_active
                    && $profile?->is_verified
                    && $profile->user !== null
                    && $profile->user->suspended_at === null;
            })
            ->map(fn (DiagnosticRecommendation $recommendation) => [
                'id' => $recommendation->id,
                'rank' => $recommendation->rank,
                'teacher_profile_id' => $recommendation->teacher_profile_id,
                'teacher_name' => $recommendation->classOffer->teacherProfile->user->name,
                'avatar_url' => $recommendation->classOffer->teacherProfile->user->avatar_url,
                'subject' => $recommendation->classOffer->subject?->name,
                'offer_title' => $recommendation->classOffer->title,
                'hourly_rate' => $recommendation->classOffer->specific_rate
                    ?? $recommendation->classOffer->teacherProfile->hourly_rate,
                'avg_rating' => $recommendation->classOffer->teacherProfile->avgRating(),
                'review_count' => $recommendation->classOffer->teacherProfile->reviewCount(),
                // Las razones ya vienen en castellano desde el servicio
                // ("Enseña Matemática", "Atiende el nivel educativo de tu
                // hijo"). Son exactamente lo que hay que mostrar.
                'reasons' => $recommendation->reasons ?? [],
                // El `score` interno (0–100) NO se expone: un "87" sin escala
                // ni unidades no significa nada para un padre y solo invita a
                // compararlo con un 85 como si la diferencia importara. Se
                // traduce a una banda cualitativa.
                'match_label' => $this->matchLabel((int) $recommendation->score),
            ])
            ->values()
            ->all();
    }

    private function matchLabel(int $score): string
    {
        return match (true) {
            $score >= 80 => 'Coincidencia muy alta',
            $score >= 60 => 'Buena coincidencia',
            default => 'Coincidencia parcial',
        };
    }

    /**
     * Ruta legacy sin ningún consumidor real (verificado: cero referencias
     * en resources/js/). Antes de la solicitud genérica automática, el padre
     * elegía una oferta específica desde una lista de recomendaciones; ahora
     * store() ya crea la ClassRequest, así que esto solo existe para no
     * romper un enlace viejo (email antiguo, marcador, etc.) que aún apunte
     * aquí. Se documenta en vez de borrarla en silencio — quitarla es una
     * decisión de limpieza válida, pero no la de esta auditoría.
     */
    public function requestClass(Request $request, StudentDiagnostic $diagnostic, ClassOffer $classOffer)
    {
        return redirect()->route('class-requests.index')
            ->with('success', 'El diagnóstico ahora crea una solicitud genérica automáticamente.');
    }

    private function buildHelpNeeded(StudentDiagnostic $diagnostic): string
    {
        $goalLabels = [
            'prepare_exam'       => 'Prepararse para un examen',
            'solve_homework'     => 'Resolver tarea específica',
            'continuous_support' => 'Acompañamiento continuo',
        ];
        $urgencyLabels = [
            'today_or_tomorrow' => 'Hoy o mañana',
            'this_week'         => 'Esta semana',
            'flexible'          => 'Sin prisa',
        ];

        $goal = $goalLabels[$diagnostic->goal] ?? $diagnostic->goal;
        $urgency = $urgencyLabels[$diagnostic->urgency] ?? $diagnostic->urgency;

        return "Objetivo: {$goal}\nUrgencia: {$urgency}\n\n[El padre completó un diagnóstico inicial. El detalle del problema estará disponible tras aceptar la solicitud.]";
    }
}
