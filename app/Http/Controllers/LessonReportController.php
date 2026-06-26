<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\LessonReport;
use App\Notifications\LessonReportPublishedNotification;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LessonReportController extends Controller
{
    public function create(Lesson $lesson)
    {
        $profile = auth()->user()->teacherProfile;
        abort_unless($profile && $lesson->teacher_profile_id === $profile->id, 403);
        abort_unless(in_array($lesson->status, ['scheduled', 'completed']), 422, 'No se puede reportar esta clase.');
        if ($lesson->lessonReport()->exists()) {
            return redirect()->route('lesson-reports.show', $lesson);
        }

        $lesson->load(['student', 'classRequest.subject']);

        return Inertia::render('LessonReports/Create', [
            'lesson' => [
                'id'               => $lesson->id,
                'start_time'       => $lesson->start_time,
                'duration_minutes' => $lesson->duration_minutes,
                'status'           => $lesson->status,
                'subject'          => $lesson->classRequest?->subject?->name ?? 'Clase',
                'student_name'     => $lesson->student?->first_name . ' ' . $lesson->student?->last_name,
            ],
        ]);
    }

    public function store(Request $request, Lesson $lesson)
    {
        $profile = auth()->user()->teacherProfile;
        abort_unless($profile && $lesson->teacher_profile_id === $profile->id, 403);
        abort_unless(in_array($lesson->status, ['scheduled', 'completed']), 422);

        if ($lesson->lessonReport()->exists()) {
            return redirect()->route('lesson-reports.show', $lesson)
                ->with('info', 'Ya existe un reporte para esta clase.');
        }

        $data = $request->validate([
            'topic_covered'         => 'required|string|max:500',
            'student_performance'   => 'required|string|max:500',
            'difficulties_detected' => 'nullable|string|max:500',
            'homework_assigned'     => 'nullable|string|max:500',
            'teacher_recommendation'=> 'nullable|string|max:500',
            'next_step'             => 'nullable|string|max:500',
        ]);

        $lesson->load(['student.parent', 'classRequest.subject']);

        $report = LessonReport::create(array_merge($data, [
            'lesson_id'          => $lesson->id,
            'teacher_profile_id' => $profile->id,
            'student_id'         => $lesson->student_id,
            'sent_to_parent_at'  => now(),
        ]));

        // Mark lesson completed if still scheduled
        if ($lesson->status === 'scheduled') {
            $lesson->update(['status' => 'completed']);
        }

        // Notify parent
        $parent = $lesson->student?->parent;
        if ($parent) {
            $parent->notify(new LessonReportPublishedNotification($report));
        }

        return redirect()->route('lesson-reports.show', $lesson)
            ->with('success', 'Reporte enviado al padre correctamente.');
    }

    public function show(Lesson $lesson)
    {
        $user    = auth()->user();
        $profile = $user->teacherProfile;

        // Teacher can see their own reports; parent can see reports of their children
        $isTeacher = $profile && $lesson->teacher_profile_id === $profile->id;
        $isParent  = $lesson->student && $lesson->student->parent_user_id === $user->id;

        abort_unless($isTeacher || $isParent || $user->hasRole('admin'), 403);

        $lesson->load(['student', 'classRequest.subject', 'teacherProfile.user', 'lessonReport']);

        abort_unless($lesson->lessonReport, 404, 'No existe reporte para esta clase.');

        return Inertia::render('LessonReports/Show', [
            'lesson' => [
                'id'               => $lesson->id,
                'start_time'       => $lesson->start_time,
                'duration_minutes' => $lesson->duration_minutes,
                'subject'          => $lesson->classRequest?->subject?->name ?? 'Clase',
                'student_name'     => $lesson->student?->first_name . ' ' . $lesson->student?->last_name,
                'teacher_name'     => $lesson->teacherProfile?->user?->name,
            ],
            'report' => $lesson->lessonReport->only([
                'topic_covered', 'student_performance', 'difficulties_detected',
                'homework_assigned', 'teacher_recommendation', 'next_step', 'sent_to_parent_at',
            ]),
        ]);
    }

    public function teacherIndex()
    {
        $profile = auth()->user()->teacherProfile;
        abort_unless($profile, 403);

        $reports = LessonReport::where('teacher_profile_id', $profile->id)
            ->with(['lesson.classRequest.subject', 'student'])
            ->latest()
            ->get()
            ->map(fn ($r) => [
                'id'                => $r->id,
                'lesson_id'         => $r->lesson_id,
                'subject'           => $r->lesson?->classRequest?->subject?->name ?? 'Clase',
                'student_name'      => $r->student?->first_name . ' ' . $r->student?->last_name,
                'start_time'        => $r->lesson?->start_time,
                'topic_covered'     => $r->topic_covered,
                'sent_to_parent_at' => $r->sent_to_parent_at,
            ]);

        return Inertia::render('LessonReports/TeacherIndex', [
            'reports' => $reports,
        ]);
    }

    public function parentIndex()
    {
        $studentIds = auth()->user()->students()->pluck('id');

        $reports = LessonReport::whereIn('student_id', $studentIds)
            ->with(['lesson.classRequest.subject', 'lesson.teacherProfile.user', 'student'])
            ->latest()
            ->get()
            ->map(fn ($r) => [
                'id'                     => $r->id,
                'lesson_id'              => $r->lesson_id,
                'subject'                => $r->lesson?->classRequest?->subject?->name ?? 'Clase',
                'student_name'           => $r->student?->first_name . ' ' . $r->student?->last_name,
                'teacher_name'           => $r->lesson?->teacherProfile?->user?->name,
                'start_time'             => $r->lesson?->start_time,
                'topic_covered'          => $r->topic_covered,
                'student_performance'    => $r->student_performance,
                'difficulties_detected'  => $r->difficulties_detected,
                'homework_assigned'      => $r->homework_assigned,
                'teacher_recommendation' => $r->teacher_recommendation,
                'next_step'              => $r->next_step,
                'sent_to_parent_at'      => $r->sent_to_parent_at,
            ]);

        return Inertia::render('LessonReports/ParentIndex', [
            'reports' => $reports,
        ]);
    }
}
