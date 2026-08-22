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

        $review = DB::transaction(function () use ($lesson, $data) {
            $locked = Lesson::whereKey($lesson->id)->lockForUpdate()->firstOrFail();
            $this->assertReviewable($locked);

            return TeacherReview::create([
                'lesson_id'          => $locked->id,
                'teacher_profile_id' => $locked->teacher_profile_id,
                'parent_id'          => auth()->id(),
                'student_id'         => $locked->student_id,
                'rating'             => $data['rating'],
                'comment'            => $data['comment'] ?? null,
                'is_visible'         => true,
            ]);
        });

        $lesson = $lesson->fresh();

        if ($lesson->credits_settled_at === null) {
            // Nadie la había liquidado todavía (ni el scheduler, ni un admin):
            // esta reseña es lo que cierra la clase. maxAllowedRate() dentro de
            // consume() ya ve esta reseña recién creada, así que la tarifa
            // queda correcta sin recalcularla dos veces.
            //
            // consume() exige status ∈ CONSUMABLE_STATES. Casi siempre se
            // cumple aquí (assertReviewable ya filtró a
            // pending_parent_confirmation/completed), PERO una lección
            // 'completed' heredada de antes de C-1 sin ledger (NO_LEDGER —
            // el backfill la deja intacta a propósito, ver la migración) no
            // es consumible y consume() lanzaría RuntimeException. La reseña
            // en sí YA se guardó (transacción propia, arriba) — dejar que
            // esa excepción reviente la respuesta le mostraría un 500 al
            // padre por una anomalía que no causó y que no puede resolver.
            // Se registra para que un admin la investigue (síntoma de una
            // lección que ya estaba mal antes de esta entrega) sin romper el
            // flujo de quien solo quería calificar.
            try {
                $lesson = app(LessonSettlementService::class)->consume($lesson, auth()->id());
            } catch (\RuntimeException $e) {
                report($e);
                $teacherProfile = TeacherProfile::whereKey($lesson->teacher_profile_id)->firstOrFail();
                $teacherProfile->update(['hourly_rate' => $teacherProfile->maxAllowedRate()]);
            }
        } else {
            // Ya estaba liquidada (auto-settlement o admin). La reseña NO mueve
            // dinero — pero sí cambió avgRating(), así que la tarifa dinámica
            // debe recalcularse igual.
            $teacherProfile = TeacherProfile::whereKey($lesson->teacher_profile_id)->firstOrFail();
            $teacherProfile->update(['hourly_rate' => $teacherProfile->maxAllowedRate()]);
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
