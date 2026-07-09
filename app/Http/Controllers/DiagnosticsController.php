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
            'subject_id'      => 'required|exists:subjects,id',
            'difficulty_text' => 'required|string|min:10|max:500',
            'school_feedback' => 'nullable|string|max:500',
            'goal'            => 'required|in:prepare_exam,solve_homework,continuous_support',
            'urgency'         => 'required|in:today_or_tomorrow,this_week,flexible',
        ]);

        $student = $user->students()->findOrFail($data['student_id']);

        $diagnostic = StudentDiagnostic::create(array_merge($data, [
            'parent_user_id' => $user->id,
            'level'          => $student->grade_level,
        ]));

        $aiService->enrich($diagnostic);
        $service->compute($diagnostic->fresh());

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

        event(new ClassRequestCreated($classRequest));

        return redirect()->route('class-requests.index')
            ->with('success', 'Diagnóstico completado. Enviamos una solicitud genérica a los profesores verificados de la materia.');
    }

    public function results(StudentDiagnostic $diagnostic)
    {
        abort_if($diagnostic->parent_user_id !== auth()->id(), 403);

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
            'recommendations' => [],
            'subjects' => Subject::orderBy('name')->get(['id', 'name']),
        ]);
    }

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
