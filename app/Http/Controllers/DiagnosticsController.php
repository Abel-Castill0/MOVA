<?php

namespace App\Http\Controllers;

use App\Events\ClassRequestCreated;
use App\Models\ClassOffer;
use App\Models\ClassRequest;
use App\Models\StudentDiagnostic;
use App\Models\Subject;
use App\Services\DiagnosticAiEnrichmentService;
use App\Services\DiagnosticRecommendationService;
use Illuminate\Http\Request;
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
            'subject_id'      => 'nullable|exists:subjects,id',
            'difficulty_text' => 'required|string|min:10|max:500',
            'school_feedback' => 'nullable|string|max:500',
            'goal'            => 'required|in:reinforce_topic,prepare_exam,recover_grades,solve_homework,continuous_support',
            'urgency'         => 'required|in:today_or_tomorrow,this_week,flexible',
        ]);

        // Verify student belongs to parent
        $student = $user->students()->findOrFail($data['student_id']);

        $diagnostic = StudentDiagnostic::create(array_merge($data, [
            'parent_user_id' => $user->id,
            'level'          => $student->grade_level,
        ]));

        // Optional AI enrichment (never blocks; fallback guaranteed)
        $aiService->enrich($diagnostic);

        // Deterministic scoring is always the authority
        $service->compute($diagnostic->fresh());

        return redirect()->route('diagnostics.results', $diagnostic);
    }

    public function results(StudentDiagnostic $diagnostic)
    {
        $user = auth()->user();

        // Only the owning parent can see results
        abort_if($diagnostic->parent_user_id !== $user->id, 403);

        $recommendations = $diagnostic->recommendations()
            ->with([
                'classOffer.subject',
                'classOffer.teacherProfile.user',
            ])
            ->orderBy('rank')
            ->get()
            ->map(fn ($rec) => [
                'id'           => $rec->id,
                'rank'         => $rec->rank,
                'score'        => $rec->score,
                'reasons'      => $rec->reasons,
                'offer' => [
                    'id'           => $rec->classOffer->id,
                    'title'        => $rec->classOffer->title,
                    'description'  => $rec->classOffer->description,
                    'specific_rate' => $rec->classOffer->specific_rate,
                    'subject'      => $rec->classOffer->subject?->name,
                    'teacher' => [
                        'id'          => $rec->classOffer->teacherProfile->id,
                        'name'        => $rec->classOffer->teacherProfile->user->name,
                        'hourly_rate' => $rec->classOffer->teacherProfile->hourly_rate,
                        'public_url'  => route('teachers.show', $rec->classOffer->teacherProfile),
                    ],
                ],
            ]);

        // Only expose ai_summary when confidence is high enough to be useful
        $aiSummary = null;
        if ($diagnostic->ai_summary && ($diagnostic->ai_confidence ?? 0) >= 60) {
            $aiSummary = $diagnostic->ai_summary;
        }

        return Inertia::render('Diagnostics/Results', [
            'diagnostic'      => [
                'id'         => $diagnostic->id,
                'goal'       => $diagnostic->goal,
                'urgency'    => $diagnostic->urgency,
                'subject'    => $diagnostic->subject?->name,
                'student'    => $diagnostic->student?->first_name,
                'ai_summary' => $aiSummary, // null if AI disabled, failed, or low confidence
            ],
            'recommendations' => $recommendations,
            'subjects'        => Subject::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function requestClass(Request $request, StudentDiagnostic $diagnostic, ClassOffer $classOffer)
    {
        $user = auth()->user();

        abort_if($diagnostic->parent_user_id !== $user->id, 403);
        abort_if(!$classOffer->is_active, 422);
        abort_if(!$classOffer->teacherProfile->is_verified, 422);

        $student = $diagnostic->student;

        $status      = $user->parental_control ? 'pending_parent_approval' : 'open';
        $helpNeeded  = $this->buildHelpNeeded($diagnostic);

        $classRequest = ClassRequest::create([
            'student_id'            => $student->id,
            'subject_id'            => $diagnostic->subject_id ?? $classOffer->subject_id,
            'class_offer_id'        => $classOffer->id,
            'help_needed'           => $helpNeeded,
            'status'                => $status,
            'student_diagnostic_id' => $diagnostic->id,
        ]);

        $diagnostic->update(['status' => 'converted']);

        event(new ClassRequestCreated($classRequest));

        return redirect()->route('class-requests.index')
            ->with('success', 'Solicitud enviada con éxito al profesor.');
    }

    /**
     * Build help_needed from diagnostic without exposing raw difficulty_text to teacher.
     * The full text is revealed only when the teacher accepts.
     */
    private function buildHelpNeeded(StudentDiagnostic $diagnostic): string
    {
        $goalLabels = [
            'reinforce_topic'    => 'Reforzar un tema',
            'prepare_exam'       => 'Prepararse para un examen',
            'recover_grades'     => 'Recuperar notas',
            'solve_homework'     => 'Resolver tarea específica',
            'continuous_support' => 'Acompañamiento continuo',
        ];
        $urgencyLabels = [
            'today_or_tomorrow' => 'Hoy o mañana',
            'this_week'         => 'Esta semana',
            'flexible'          => 'Sin prisa',
        ];

        $goal    = $goalLabels[$diagnostic->goal] ?? $diagnostic->goal;
        $urgency = $urgencyLabels[$diagnostic->urgency] ?? $diagnostic->urgency;

        return "Objetivo: {$goal}\nUrgencia: {$urgency}\n\n[El padre completó un diagnóstico inicial. El detalle del problema estará disponible tras aceptar la solicitud.]";
    }
}
