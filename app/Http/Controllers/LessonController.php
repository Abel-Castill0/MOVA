<?php

namespace App\Http\Controllers;

use App\Events\ClassConfirmed;
use App\Models\ClassRequest;
use App\Models\Lesson;
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
                ->with(['teacherProfile.user', 'student', 'classRequest.subject'])
                ->orderBy('start_time', 'desc')
                ->get(),
        ]);
    }

    public function teacherIndex()
    {
        $profile = auth()->user()->teacherProfile;

        return Inertia::render('Lessons/TeacherIndex', [
            'lessons' => Lesson::where('teacher_profile_id', $profile->id)
                ->with(['student', 'classRequest.subject'])
                ->orderBy('start_time', 'desc')
                ->get(),
        ]);
    }

    public function cancel(Lesson $lesson, ZoomService $zoom)
    {
        $profile = auth()->user()->teacherProfile;
        abort_unless($lesson->teacher_profile_id === $profile->id, 403);
        abort_unless($lesson->status === 'scheduled', 422, 'Solo se pueden cancelar clases programadas.');

        $lesson->update(['status' => 'cancelled']);

        if ($lesson->zoom_meeting_id) {
            $zoom->deleteMeeting($lesson->zoom_meeting_id);
        }

        return back()->with('success', 'Clase cancelada correctamente.');
    }
}
