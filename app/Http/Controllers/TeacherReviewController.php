<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\TeacherProfile;
use App\Models\TeacherReview;
use App\Notifications\TeacherReviewReceivedNotification;
use App\Services\LessonSettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class TeacherReviewController extends Controller
{
    // GET /lessons/{lesson}/review/create
    public function create(Lesson $lesson)
    {
        $this->authorize('createReview', $lesson);
        $this->assertReviewable($lesson);

        $lesson->load(['teacherProfile.user', 'student']);

        return Inertia::render('Reviews/Create', [
            'lesson' => [
                'id'      => $lesson->id,
                'subject' => $lesson->classRequest?->subject?->name ?? 'Clase',
                'teacher' => $lesson->teacherProfile?->user?->name,
                'date'    => $lesson->start_time,
                'student' => $lesson->student ? ($lesson->student->first_name . ' ' . $lesson->student->last_name) : null,
            ],
        ]);
    }

    // POST /lessons/{lesson}/review
    //
    // C-1: la clase ya puede llegar aquí en dos caminos distintos —
    // 'pending_parent_confirmation' (flujo manual de siempre) o 'completed'
    // (ya se auto-liquidó porque nadie reseñó antes de la ventana de gracia).
    // La reseña SIEMPRE se acepta en ambos; lo que cambia es si además debe
    // disparar la liquidación (solo si nadie la liquidó todavía).
    public function store(Request $request, Lesson $lesson)
    {
        $this->authorize('createReview', $lesson);
        $this->assertReviewable($lesson);

        $data = $request->validate([
            'rating'  => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        $legacyUnsettledLessonId = null;
        [$review, $lesson] = DB::transaction(function () use ($lesson, $data, &$legacyUnsettledLessonId) {
            $locked = Lesson::whereKey($lesson->id)->lockForUpdate()->firstOrFail();
            $this->assertReviewable($locked);

            $review = TeacherReview::create([
                'lesson_id'          => $locked->id,
                'teacher_profile_id' => $locked->teacher_profile_id,
                'parent_id'          => auth()->id(),
                'student_id'         => $locked->student_id,
                'rating'             => $data['rating'],
                'comment'            => $data['comment'] ?? null,
                'is_visible'         => true,
            ]);

            // La reseña, el ledger y el recálculo de tarifa comparten la MISMA
            // transacción: un error inesperado revierte todo y permite reintentar.
            if ($locked->status === 'completed' && $locked->credits_settled_at === null) {
                // Solo legado NO_LEDGER. No llamar a consume() con un estado
                // deliberadamente no consumible ni suprimir fallos reales.
                $legacyUnsettledLessonId = $locked->id;
                $teacherProfile = TeacherProfile::whereKey($locked->teacher_profile_id)->lockForUpdate()->firstOrFail();
                $teacherProfile->update(['hourly_rate' => $teacherProfile->maxAllowedRate()]);
            } elseif ($locked->credits_settled_at === null) {
                $locked = app(LessonSettlementService::class)->consume($locked, auth()->id());
            } else {
                // Auto-liquidada o liquidada por admin: la reseña cambia la
                // valoración, nunca crea un segundo consumo de créditos.
                $teacherProfile = TeacherProfile::whereKey($locked->teacher_profile_id)->lockForUpdate()->firstOrFail();
                $teacherProfile->update(['hourly_rate' => $teacherProfile->maxAllowedRate()]);
            }

            return [$review, $locked];
        });

        // La telemetría se emite después del commit, fuera de locks de dinero.
        if ($legacyUnsettledLessonId !== null) {
            report(new \RuntimeException("Lección {$legacyUnsettledLessonId} completada sin asiento de liquidación histórico: revisión manual requerida."));
        }

        // Notify teacher
        $teacher = $lesson->teacherProfile?->user;
        if ($teacher) {
            $teacher->notify(new TeacherReviewReceivedNotification($review));
        }

        return redirect()->route('parent.lessons')->with('success', 'Reseña enviada. ¡Gracias por tu opinión!');
    }

    /**
     * Gate único de reseña, reutilizado por create() y store() (fuera y
     * dentro del lock). 'completed' se admite además de
     * 'pending_parent_confirmation' porque C-1 puede llegar a ese estado sin
     * pasar por una reseña previa — pero el reporte del profesor sigue siendo
     * obligatorio en ambos casos: antes, "pending_parent_confirmation" ya lo
     * implicaba por construcción del estado anterior; ahora hay que exigirlo
     * explícitamente porque 'completed' ya no lo garantiza.
     */
    private function assertReviewable(Lesson $lesson): void
    {
        abort_unless(
            in_array($lesson->status, ['pending_parent_confirmation', 'completed'], true),
            403,
            'Solo se puede calificar una clase con el reporte del profesor listo.'
        );
        abort_unless($lesson->lessonReport()->exists(), 403, 'Solo se puede calificar una clase con el reporte del profesor listo.');
        abort_if($lesson->teacherReview()->exists(), 422, 'Ya existe una reseña para esta clase.');
    }

    // GET /admin/reviews
    public function adminIndex(Request $request)
    {
        $query = TeacherReview::with([
            'teacherProfile.user:id,name',
            'parent:id,name',
            'lesson',
        ])->latest();

        if ($request->filled('visibility')) {
            $query->where('is_visible', $request->visibility === 'visible');
        }

        return Inertia::render('Admin/Reviews', [
            'reviews'    => $query->paginate(30)->withQueryString(),
            'visibility' => $request->visibility,
        ]);
    }

    // POST /admin/reviews/{review}/hide
    public function hide(Request $request, TeacherReview $review)
    {
        $this->authorize('moderate', $review);
        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $review->update([
            'is_visible'        => false,
            'moderated_at'      => now(),
            'moderated_by'      => auth()->id(),
            'moderation_reason' => $data['reason'] ?? null,
        ]);

        return back()->with('success', 'Reseña ocultada.');
    }

    // POST /admin/reviews/{review}/show
    public function showReview(TeacherReview $review)
    {
        $this->authorize('moderate', $review);
        $review->update([
            'is_visible'        => true,
            'moderated_at'      => now(),
            'moderated_by'      => auth()->id(),
            'moderation_reason' => null,
        ]);

        return back()->with('success', 'Reseña visible de nuevo.');
    }
}
