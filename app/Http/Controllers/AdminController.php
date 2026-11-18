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
use App\Services\AccountLifecycleService;
use App\Services\LessonSettlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class AdminController extends Controller
{
    public function users()
    {
        $search = trim((string) request('q', ''));
        $showErased = request()->boolean('erased');

        $users = User::with('roles:id,name')
            ->select(['id', 'name', 'email', 'phone', 'suspended_at', 'suspension_reason', 'account_closed_at', 'account_erased_at', 'created_at'])
            ->when(! $showErased, fn ($query) => $query->whereNull('account_erased_at'))
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like));
            })
            ->latest()->latest('id')
            ->paginate(20)->withQueryString();

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'filters' => ['q' => $search, 'erased' => $showErased],
        ]);
    }

    /** Consecuencias de eliminar una cuenta, para mostrarlas antes de confirmar (solo lectura). */
    public function erasurePreview(User $user, AccountLifecycleService $lifecycle)
    {
        $summary = $lifecycle->summary($user);
        $blockers = $user->hasRole('admin')
            ? ['Una cuenta de administrador no se puede eliminar desde aquí.']
            : ($user->account_closed_at !== null ? ['La cuenta ya está cerrada.'] : $lifecycle->erasureBlockers($summary));

        return response()->json([
            'summary' => array_intersect_key($summary, array_flip([
                'roles', 'students', 'future_classes', 'pending_classes', 'active_requests', 'credits_available',
                'credits_refundable', 'credits_forfeitable', 'pending_payments', 'payment_reviews', 'recharges', 'financial_records',
            ])),
            'suspended' => $user->suspended_at !== null,
            'in_debt' => (int) ($user->teacherProfile?->credits_available ?? 0) < 0,
            'blockers' => $blockers,
        ]);
    }

    public function eraseUser(User $user, AccountLifecycleService $lifecycle)
    {
        if ($user->hasRole('admin') || $user->is(auth()->user())) {
            return back()->with('error', 'No se puede eliminar a un administrador.');
        }
        if ($user->account_closed_at !== null) {
            return back()->with('error', 'Esa cuenta ya estaba cerrada.');
        }

        $data = request()->validate([
            'reason' => 'required|string|min:10|max:500',
            'confirm_email' => 'required|string|max:255',
            'accept_credit_loss' => 'nullable|integer|min:0',
        ], [
            'reason.required' => 'Indica el motivo de la eliminación (queda en el registro de auditoría).',
            'reason.min' => 'Describe el motivo con al menos 10 caracteres.',
            'confirm_email.required' => 'Escribe el correo de la cuenta para confirmar.',
        ]);
        if (strcasecmp(trim($data['confirm_email']), $user->email) !== 0) {
            throw ValidationException::withMessages(['confirm_email' => 'El correo escrito no coincide con el de la cuenta.']);
        }

        $deletion = $lifecycle->eraseByAdmin($user, auth()->user(), trim($data['reason']), (int) ($data['accept_credit_loss'] ?? 0));

        // Solo identificadores: ni correo, ni nombre, ni teléfono, ni el texto libre del motivo (queda, redactado, en la constancia).
        Log::info('ADMIN_USER_ERASED', [
            'admin_id' => auth()->id(),
            'user_id' => $user->id,
            'reference' => $deletion->reference,
        ]);

        return back()->with('success', 'Cuenta eliminada. Sus datos personales se anonimizaron y el correo quedó libre para un alta nueva.');
    }

    /**
     * Encontrado al verificar que `reviewedBy:id,name` (ya acotado) no
     * tuviera un hermano sin acotar en el mismo método: la relación `user`
     * de arriba se cargaba SIN restricción de columnas — verificado con
     * json_encode() que eso enviaba el `User` completo a un admin,
     * incluyendo `phone_verification_code_hash`, `suspension_reason`,
     * `whatsapp_opt_in_at`/`opt_out_at`, etc. No es la misma clase P0 que
     * `/marketplace`/`/` (esta ruta exige `role:admin`, verificado en
     * routes/web.php — no es una superficie pública), pero es el mismo
     * patrón de raíz (relación cargada sin proyección) y el mismo
     * principio de exposición mínima aplica igual para un admin.
     * `Admin/PendingTeachers.vue` solo lee `name`/`email` de esta relación.
     */
    public function pendingTeachers()
    {
        return Inertia::render('Admin/PendingTeachers', [
            'pendingTeachers' => TeacherProfile::where('is_verified', false)
                ->whereNull('rejected_at')
                ->with(['user:id,name,email', 'subjects:id,name'])
                ->get()
                ->each(fn (TeacherProfile $teacher) => $teacher->subjects->each->makeHidden('pivot')),
            'rejectedTeachers' => TeacherProfile::where('is_verified', false)
                ->whereNotNull('rejected_at')
                ->with(['user:id,name,email', 'subjects:id,name', 'reviewedBy:id,name'])
                ->latest('rejected_at')
                ->get()
                ->each(fn (TeacherProfile $teacher) => $teacher->subjects->each->makeHidden('pivot')),
        ]);
    }

    public function verifyTeacher(TeacherProfile $teacher)
    {
        $teacher = DB::transaction(function () use ($teacher) {
            $owner = \App\Models\User::whereKey($teacher->user_id)->lockForUpdate()->firstOrFail();
            abort_if($owner->account_closed_at !== null, 409, 'La cuenta está cerrada y no puede publicarse.');
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

        if ($user->account_closed_at !== null) {
            return back()->with('error', 'La cuenta está cerrada: no hay nada que suspender.');
        }

        $user->update([
            'suspended_at'      => now(),
            'suspension_reason' => $data['reason'] ?? 'Suspensión por incumplimiento de términos.',
        ]);

        Log::info('ADMIN_USER_SUSPENDED', ['admin_id' => auth()->id(), 'user_id' => $user->id]);

        return back()->with('success', "Usuario {$user->name} suspendido.");
    }

    public function unsuspendUser(User $user)
    {
        if ($user->account_closed_at !== null) {
            return back()->with('error', 'La cuenta está cerrada y no puede reactivarse.');
        }

        $user->update([
            'suspended_at'      => null,
            'suspension_reason' => null,
        ]);

        Log::info('ADMIN_USER_UNSUSPENDED', ['admin_id' => auth()->id(), 'user_id' => $user->id]);

        return back()->with('success', "Cuenta de {$user->name} reactivada.");
    }
}
