<?php

namespace App\Http\Controllers;

use App\Events\ClassConfirmed;
use App\Models\ClassEvent;
use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Notifications\ClassCancelledNotification;
use App\Notifications\ClassRescheduledNotification;
use App\Services\ZoomService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;

class LessonController extends Controller
{
    public function store(Request $request, ZoomService $zoom)
    {
        $data = $request->validate([
            'class_request_id' => 'required|exists:class_requests,id',
            'start_time'       => 'required|date|after:now',
            'duration_minutes' => 'required|integer|min:30|max:240',
        ]);

        $classRequest = ClassRequest::with(['student', 'subject'])->findOrFail($data['class_request_id']);
        $profile      = auth()->user()->teacherProfile;

        abort_unless($profile, 403, 'No tienes perfil de profesor.');

        // Verify this request belongs to this teacher (via offer or matching subject)
        $offerIds   = $profile->classOffers()->pluck('id');
        $subjectIds = $profile->subjects()->pluck('subjects.id');
        $ownedViaOffer   = $classRequest->class_offer_id && $offerIds->contains($classRequest->class_offer_id);
        $ownedViaSubject = !$classRequest->class_offer_id && $subjectIds->contains($classRequest->subject_id);
        abort_unless($ownedViaOffer || $ownedViaSubject, 403, 'Esta solicitud no pertenece a tus ofertas.');

        // Overlap check
        $overlap = Lesson::where('teacher_profile_id', $profile->id)
            ->where('status', 'scheduled')
            ->whereRaw("start_time < DATE_ADD(?, INTERVAL ? MINUTE)", [$data['start_time'], $data['duration_minutes']])
            ->whereRaw("DATE_ADD(start_time, INTERVAL duration_minutes MINUTE) > ?", [$data['start_time']])
            ->exists();

        if ($overlap) {
            return back()->withErrors(['start_time' => 'Ya tienes una clase en ese horario.']);
        }

        // Create Zoom meeting — throws RuntimeException if credentials are missing or API fails
        try {
            $meeting = $zoom->createMeeting(
                'MOVA: ' . ($classRequest->subject->name ?? 'Clase'),
                $data['start_time'],
                $data['duration_minutes']
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['zoom' => $e->getMessage()]);
        }

        $lesson = Lesson::create([
            'teacher_profile_id' => $profile->id,
            'student_id'         => $classRequest->student_id,
            'class_request_id'   => $classRequest->id,
            'class_offer_id'     => $classRequest->class_offer_id,
            'start_time'         => $data['start_time'],
            'duration_minutes'   => $data['duration_minutes'],
            'zoom_meeting_id'    => $meeting['meeting_id'],
            'zoom_link'          => $meeting['join_url'],
            'zoom_password'      => $meeting['password'],
            'status'             => 'scheduled',
        ]);

        $classRequest->update(['status' => 'accepted']);

        event(new ClassConfirmed($lesson));

        return redirect()->route('teacher.lessons')->with('success',
            '¡Clase programada! El enlace de Zoom está disponible en "Mis clases".'
        );
    }

    public function parentIndex()
    {
        $studentIds = auth()->user()->students()->pluck('id');

        return Inertia::render('Lessons/ParentIndex', [
            'lessons' => Lesson::whereIn('student_id', $studentIds)
                ->with(['teacherProfile.user', 'student', 'classRequest.subject', 'teacherReview'])
                ->orderBy('start_time', 'desc')
                ->get(),
        ]);
    }

    public function teacherIndex()
    {
        $profile = auth()->user()->teacherProfile;

        return Inertia::render('Lessons/TeacherIndex', [
            'lessons' => Lesson::where('teacher_profile_id', $profile->id)
                ->with(['student', 'classRequest.subject', 'lessonReport'])
                ->orderBy('start_time', 'desc')
                ->get(),
        ]);
    }

    public function complete(Lesson $lesson)
    {
        $profile = auth()->user()->teacherProfile;
        abort_unless($profile && $lesson->teacher_profile_id === $profile->id, 403);
        abort_unless($lesson->status === 'scheduled', 422, 'Solo se pueden completar clases programadas.');

        $lesson->update(['status' => 'completed']);

        return redirect()->route('lesson-reports.create', $lesson)
            ->with('success', 'Clase marcada como completada. Ahora puedes crear el reporte.');
    }

    public function cancel(Lesson $lesson, ZoomService $zoom)
    {
        $user    = auth()->user();
        $profile = $user->teacherProfile;

        // Authorization: teacher (own lesson), parent (own student), or admin
        $isTeacher = $profile && $lesson->teacher_profile_id === $profile->id;
        $isParent  = $user->hasRole('parent') && $user->students()->where('id', $lesson->student_id)->exists();
        $isAdmin   = $user->hasRole('admin');
        abort_unless($isTeacher || $isParent || $isAdmin, 403);
        abort_unless($lesson->status === 'scheduled', 422, 'Solo se pueden cancelar clases programadas.');

        $data = request()->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $lesson->load(['student.parent', 'teacherProfile.user']);
        $lesson->update([
            'status'       => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => $user->id,
            'cancel_reason' => $data['reason'] ?? null,
        ]);

        ClassEvent::log('class_cancelled', $user->id, $lesson->id, $lesson->class_request_id, $data['reason'] ?? null);

        if ($lesson->zoom_meeting_id) {
            $zoom->deleteMeeting($lesson->zoom_meeting_id);
        }

        $notification = new ClassCancelledNotification($lesson);

        // Notify the other party (not the one who cancelled)
        if (!$isTeacher && $lesson->teacherProfile?->user) {
            $lesson->teacherProfile->user->notify($notification);
        }
        if (!$isParent && $lesson->student?->parent) {
            $lesson->student->parent->notify($notification);
        }
        // Admin cancels → notify both
        if ($isAdmin) {
            $lesson->teacherProfile?->user?->notify($notification);
            $lesson->student?->parent?->notify($notification);
        }

        return back()->with('success', 'Clase cancelada correctamente.');
    }

    public function reschedule(Lesson $lesson)
    {
        $user    = auth()->user();
        $profile = $user->teacherProfile;

        $isTeacher = $profile && $lesson->teacher_profile_id === $profile->id;
        $isParent  = $user->hasRole('parent') && $user->students()->where('id', $lesson->student_id)->exists();
        abort_unless($isTeacher || $isParent, 403);
        abort_unless($lesson->status === 'scheduled', 422, 'Solo se pueden reprogramar clases programadas.');

        $data = request()->validate([
            'start_time'       => 'required|date|after:now',
            'duration_minutes' => 'required|integer|min:30|max:240',
            'reason'           => 'nullable|string|max:500',
        ]);

        // Overlap check for teacher
        $overlap = Lesson::where('teacher_profile_id', $lesson->teacher_profile_id)
            ->where('id', '!=', $lesson->id)
            ->where('status', 'scheduled')
            ->whereRaw("start_time < DATE_ADD(?, INTERVAL ? MINUTE)", [$data['start_time'], $data['duration_minutes']])
            ->whereRaw("DATE_ADD(start_time, INTERVAL duration_minutes MINUTE) > ?", [$data['start_time']])
            ->exists();

        if ($overlap) {
            return back()->withErrors(['start_time' => 'El profesor ya tiene una clase en ese horario.']);
        }

        $originalStart = $lesson->original_start_time ?? $lesson->start_time;

        $lesson->load(['student.parent', 'teacherProfile.user']);
        $lesson->update([
            'start_time'          => $data['start_time'],
            'duration_minutes'    => $data['duration_minutes'],
            'original_start_time' => $originalStart,
            'rescheduled_at'      => now(),
            'rescheduled_by'      => $user->id,
            'reschedule_reason'   => $data['reason'] ?? null,
        ]);

        ClassEvent::log('class_rescheduled', $user->id, $lesson->id, $lesson->class_request_id, $data['reason'] ?? null, [
            'new_start_time'  => $data['start_time'],
            'original_start'  => $originalStart,
        ]);

        $changedByName = $isTeacher ? 'el profesor' : 'el padre/tutor';
        $notification  = new ClassRescheduledNotification($lesson, $changedByName);

        // Notify both parties
        $lesson->teacherProfile?->user?->notify($notification);
        $lesson->student?->parent?->notify($notification);

        return back()->with('success', 'Clase reprogramada correctamente.');
    }
}
