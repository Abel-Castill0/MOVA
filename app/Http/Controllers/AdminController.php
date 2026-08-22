<?php

namespace App\Http\Controllers;

use App\Models\ClassEvent;
use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Notifications\ClassCancelledNotification;
use App\Notifications\TeacherRejectedNotification;
use App\Notifications\TeacherVerifiedNotification;
use App\Services\LessonSettlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class AdminController extends Controller
{
    public function users()
    {
        return Inertia::render('Admin/Users', [
            'users' => User::with('roles')->latest()->paginate(20),
        ]);
    }

    public function pendingTeachers()
    {
        return Inertia::render('Admin/PendingTeachers', [
            'pendingTeachers' => TeacherProfile::where('is_verified', false)
                ->whereNull('rejected_at')
                ->with(['user', 'subjects'])
                ->get(),
            'rejectedTeachers' => TeacherProfile::where('is_verified', false)
                ->whereNotNull('rejected_at')
                ->with(['user', 'subjects', 'reviewedBy:id,name'])
                ->latest('rejected_at')
                ->get(),
        ]);
    }

    public function verifyTeacher(TeacherProfile $teacher)
    {
        $teacher = DB::transaction(function () use ($teacher) {
            $teacher = TeacherProfile::whereKey($teacher->id)->lockForUpdate()->firstOrFail();

            $teacher->update([
                'is_verified'      => true,
                'rejected_at'      => null,
                'rejection_reason' => null,
                'reviewed_by'      => auth()->id(),
                'reviewed_at'      => now(),
            ]);

            return $teacher->load('user');
        });

        $teacher->user->notify(new TeacherVerifiedNotification());

        Log::info('ADMIN_TEACHER_VERIFIED', [
            'admin_id'           => auth()->id(),
            'teacher_profile_id' => $teacher->id,
        ]);

        return back()->with('success', 'Profesor verificado correctamente.');
    }

    public function rejectTeacher(TeacherProfile $teacher)
    {
        $data = request()->validate([
            'reason' => 'required|string|min:10|max:500',
        ]);

        // Safe rejection — never deletes the user
        $teacher->update([
            'is_verified'      => false,
            'rejected_at'      => now(),
            'rejection_reason' => $data['reason'],
            'reviewed_by'      => auth()->id(),
            'reviewed_at'      => now(),
        ]);

        // Deactivate all class offers (marketplace already filters is_verified=true,
        // but deactivating keeps data consistent if teacher is re-verified later)
        $teacher->classOffers()->update(['is_active' => false]);

        // Notify teacher via in-app + email (if verified)
        $teacher->user->notify(new TeacherRejectedNotification($teacher));

        Log::info('ADMIN_TEACHER_REJECTED', [
            'admin_id'           => auth()->id(),
            'teacher_profile_id' => $teacher->id,
            'rejection_reason'   => $data['reason'],
        ]);

        return back()->with('success', "Perfil de {$teacher->user->name} rechazado. El profesor ha sido notificado.");
    }

    public function requests()
    {
        $status = request()->query('status');

        $query = ClassRequest::with(['student.parent', 'subject', 'classOffer.teacherProfile.user', 'lesson.teacherProfile.user'])
            ->latest();

        if ($status) {
            $query->where('status', $status);
        }

        return Inertia::render('Admin/Requests', [
            'requests'       => $query->paginate(30)->withQueryString(),
            'statusFilter'   => $status,
        ]);
    }

    public function lessons()
    {
        $status = request()->query('status');

        $query = Lesson::with(['teacherProfile.user', 'student.parent', 'classRequest.subject'])
            ->orderBy('start_time', 'desc');

        if ($status) {
            $query->where('status', $status);
        }

        return Inertia::render('Admin/Lessons', [
            'lessons'      => $query->paginate(30)->withQueryString(),
            'statusFilter' => $status,
        ]);
    }

    public function cancelLesson(Lesson $lesson)
    {
        abort_unless($lesson->status === 'scheduled', 422, 'Solo se pueden cancelar clases programadas.');

        $data = request()->validate([
            'reason' => 'required|string|min:5|max:500',
        ]);

        $lesson = DB::transaction(function () use ($lesson, $data) {
            $lesson = Lesson::with(['student.parent', 'teacherProfile.user', 'classRequest'])
                ->whereKey($lesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($lesson->status === 'scheduled', 422, 'Solo se pueden cancelar clases programadas.');

            $teacherProfile = TeacherProfile::whereKey($lesson->teacher_profile_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Se devuelve exactamente lo reservado en su momento (ledger), no lo
            // que costaría hoy — ver Lesson::reservedCreditAmount().
            //
            // Puede lanzar RuntimeException si el ledger no respalda exactamente
            // 1 reserva (anomalía real, ej. una clase legacy NO_LEDGER) — se
            // convierte a un 422 accionable en vez de un 500 crudo.
            try {
                $creditsToRefund = $lesson->reservedCreditAmount();
            } catch (\RuntimeException $e) {
                report($e);
                abort(422, 'Esta clase tiene una anomalía financiera y no se puede cancelar automáticamente. Contacta a soporte.');
            }

            abort_if(
                $teacherProfile->credits_reserved < $creditsToRefund,
                422,
                'No hay créditos reservados suficientes para devolver esta clase.'
            );

            $profileUpdates = [
                'credits_available' => $teacherProfile->credits_available + $creditsToRefund,
                'credits_reserved'  => $teacherProfile->credits_reserved - $creditsToRefund,
            ];

            if ($lesson->classRequest?->is_mentorship) {
                $profileUpdates['mentorship_slots_taken'] = max(
                    0,
                    $teacherProfile->mentorship_slots_taken - 1
                );
            }

            $teacherProfile->update($profileUpdates);

            $teacherProfile->creditTransactions()->create([
                'idempotency_key' => "lesson:{$lesson->id}:release",
                'lesson_id'   => $lesson->id,
                'type'        => 'refund',
                'amount'      => $creditsToRefund,
                'description' => 'Devolución por clase cancelada',
            ]);

            $lesson->update([
                'status'        => 'cancelled',
                'cancelled_at'  => now(),
                'cancelled_by'  => auth()->id(),
                'cancel_reason' => $data['reason'],
            ]);

            ClassEvent::log('class_cancelled', auth()->id(), $lesson->id, $lesson->class_request_id, $data['reason']);

            return $lesson;
        });

        $notification = new ClassCancelledNotification($lesson);
        $lesson->teacherProfile?->user?->notify($notification);
        $lesson->student?->parent?->notify($notification);

        Log::info('ADMIN_LESSON_CANCELLED', [
            'admin_id'  => auth()->id(),
            'lesson_id' => $lesson->id,
            'reason'    => $data['reason'],
        ]);

        return back()->with('success', 'Clase cancelada y partes notificadas.');
    }

    // C-1 — rescate manual (Fase 3B §7). Cubre lo que el propio cancelLesson()
    // de arriba NUNCA cubrió: clases que ya salieron de 'scheduled' (paid,
    // pending_parent_confirmation, needs_admin_review) y quedaron varadas sin
    // que nadie —ni el padre con una reseña, ni el scheduler tras la gracia—
    // las cerrara. 'scheduled' queda deliberadamente FUERA de force-complete:
    // no hay ninguna evidencia (reserva pagada, reporte, nada) de que la
    // clase haya ocurrido, así que "completarla a la fuerza" sería fabricar
    // un desenlace. Para 'scheduled' varada, la salida es force-refund.
    public function forceCompleteLesson(Lesson $lesson, LessonSettlementService $settlement)
    {
        // Chequeo amistoso antes del servicio: el guard real (bajo lock, a
        // prueba de carreras) vive dentro de consume() y lanza RuntimeException
        // si falla — esto solo evita un 500 feo en el caso común de que el
        // admin apunte a un estado no liquidable.
        abort_unless(
            in_array($lesson->status, ['paid', 'pending_parent_confirmation', 'needs_admin_review'], true),
            422,
            'Solo se puede forzar el cierre de una clase paid, pending_parent_confirmation o needs_admin_review.'
        );

        $data = request()->validate([
            'reason' => 'required|string|min:5|max:500',
        ]);

        $lesson = $settlement->consume($lesson, auth()->id(), $data['reason'], 'class_force_completed');

        Log::info('ADMIN_LESSON_FORCE_COMPLETED', [
            'admin_id'  => auth()->id(),
            'lesson_id' => $lesson->id,
            'reason'    => $data['reason'],
        ]);

        return back()->with('success', 'Clase liquidada manualmente (crédito consumido).');
    }

    public function forceRefundLesson(Lesson $lesson, LessonSettlementService $settlement)
    {
        abort_unless(
            in_array($lesson->status, ['scheduled', 'paid', 'pending_parent_confirmation', 'needs_admin_review'], true),
            422,
            'Solo se puede forzar la devolución de una clase scheduled, paid, pending_parent_confirmation o needs_admin_review.'
        );

        $data = request()->validate([
            'reason' => 'required|string|min:5|max:500',
        ]);

        $lesson = $settlement->refund($lesson, auth()->id(), $data['reason'], 'class_force_refunded');

        $notification = new ClassCancelledNotification($lesson);
        $lesson->teacherProfile?->user?->notify($notification);
        $lesson->student?->parent?->notify($notification);

        Log::info('ADMIN_LESSON_FORCE_REFUNDED', [
            'admin_id'  => auth()->id(),
            'lesson_id' => $lesson->id,
            'reason'    => $data['reason'],
        ]);

        return back()->with('success', 'Clase devuelta manualmente (crédito liberado).');
    }

    public function suspendUser(User $user)
    {
        if ($user->hasRole('admin')) {
            return back()->with('error', 'No se puede suspender a un administrador.');
        }

        $data = request()->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $user->update([
            'suspended_at'      => now(),
            'suspension_reason' => $data['reason'] ?? 'Suspensión por incumplimiento de términos.',
        ]);

        return back()->with('success', "Usuario {$user->name} suspendido.");
    }

    public function unsuspendUser(User $user)
    {
        $user->update([
            'suspended_at'      => null,
            'suspension_reason' => null,
        ]);

        return back()->with('success', "Cuenta de {$user->name} reactivada.");
    }
}
