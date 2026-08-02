<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\TeacherProfile;
use App\Models\TeacherReview;
use App\Notifications\TeacherReviewReceivedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class TeacherReviewController extends Controller
{
    // GET /lessons/{lesson}/review/create
    public function create(Lesson $lesson)
    {
        $this->authorize('createReview', $lesson);
        abort_unless($lesson->status === 'pending_parent_confirmation', 403, 'Solo se puede calificar una clase con el reporte del profesor listo.');
        abort_if($lesson->teacherReview()->exists(), 422, 'Ya existe una reseña para esta clase.');

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
    public function store(Request $request, Lesson $lesson)
    {
        $this->authorize('createReview', $lesson);
        abort_unless($lesson->status === 'pending_parent_confirmation', 403, 'Solo se puede calificar una clase con el reporte del profesor listo.');
        abort_if($lesson->teacherReview()->exists(), 422, 'Ya enviaste una reseña para esta clase.');

        $data = $request->validate([
            'rating'  => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        $review = DB::transaction(function () use ($lesson, $data) {
            $lesson = Lesson::whereKey($lesson->id)->lockForUpdate()->firstOrFail();

            abort_unless($lesson->status === 'pending_parent_confirmation', 403, 'Solo se puede calificar una clase con el reporte del profesor listo.');
            abort_if($lesson->teacherReview()->exists(), 422, 'Ya enviaste una reseña para esta clase.');

            $teacherProfile = TeacherProfile::whereKey($lesson->teacher_profile_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Se consume exactamente lo reservado en su momento (ledger), no lo
            // que costaría hoy — ver Lesson::reservedCreditAmount().
            $creditsToConsume = $lesson->reservedCreditAmount();

            abort_if(
                $teacherProfile->credits_reserved < $creditsToConsume,
                422,
                'No hay créditos reservados suficientes para cerrar esta clase.'
            );

            $review = TeacherReview::create([
                'lesson_id'          => $lesson->id,
                'teacher_profile_id' => $lesson->teacher_profile_id,
                'parent_id'          => auth()->id(),
                'student_id'         => $lesson->student_id,
                'rating'             => $data['rating'],
                'comment'            => $data['comment'] ?? null,
                'is_visible'         => true,
            ]);

            $teacherProfile->update([
                'credits_reserved' => $teacherProfile->credits_reserved - $creditsToConsume,
                'completed_classes_count' => $teacherProfile->completed_classes_count + 1,
                'is_experienced' => ($teacherProfile->completed_classes_count + 1) >= 5,
            ]);

            // avgRating() consulta visibleReviews() en vivo, así que ya incluye la
            // reseña recién creada — el nivel se recalcula con datos actualizados.
            $teacherProfile->update(['hourly_rate' => $teacherProfile->maxAllowedRate()]);

            $teacherProfile->creditTransactions()->create([
                'idempotency_key' => "lesson:{$lesson->id}:consumption",
                'lesson_id' => $lesson->id,
                'type' => 'consumption',
                'amount' => $creditsToConsume,
                'description' => 'Consumo por clase completada',
            ]);

            $lesson->update(['status' => 'completed']);

            return $review;
        });

        // Notify teacher
        $teacher = $lesson->teacherProfile?->user;
        if ($teacher) {
            $teacher->notify(new TeacherReviewReceivedNotification($review));
        }

        return redirect()->route('parent.lessons')->with('success', 'Reseña enviada. ¡Gracias por tu opinión!');
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
