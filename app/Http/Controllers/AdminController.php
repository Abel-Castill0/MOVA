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
        $teacher->update([
            'is_verified'      => true,
            'rejected_at'      => null,
            'rejection_reason' => null,
            'reviewed_by'      => auth()->id(),
            'reviewed_at'      => now(),
        ]);

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

        $query = ClassRequest::with(['student.parent', 'subject', 'classOffer.teacherProfile.user'])
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

        $lesson->load(['student.parent', 'teacherProfile.user']);
        $lesson->update([
            'status'       => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => auth()->id(),
            'cancel_reason' => $data['reason'],
        ]);

        ClassEvent::log('class_cancelled', auth()->id(), $lesson->id, $lesson->class_request_id, $data['reason']);

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

    public function suspendUser(User $user)
    {
        if ($user->hasRole('admin')) {
            return back()->with('error', 'No se puede suspender a un administrador.');
        }

        $reason = request()->input('reason', 'Suspensión por incumplimiento de términos.');

        $user->update([
            'suspended_at'      => now(),
            'suspension_reason' => $reason,
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
