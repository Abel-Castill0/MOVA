<?php

namespace App\Http\Controllers;

use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $user = auth()->user();

        if ($user->hasRole('admin')) {
            return Inertia::render('Dashboard/Admin', [
                'stats' => [
                    'users' => User::count(),
                    'pending_teachers' => TeacherProfile::where('is_verified', false)->count(),
                    'classes_today' => Lesson::whereDate('start_time', today())->count(),
                    'subjects' => Subject::count(),
                ],
                'recentUsers' => User::with('roles')
                    ->latest()
                    ->take(5)
                    ->get(['id', 'name', 'email', 'created_at'])
                    ->map(fn($u) => array_merge($u->toArray(), ['roles' => $u->getRoleNames()])),
            ]);
        }

        if ($user->hasRole('teacher')) {
            $profile = $user->teacherProfile;
            return Inertia::render('Dashboard/Teacher', [
                'upcoming' => $profile ? Lesson::where('teacher_profile_id', $profile->id)
                    ->where('status', 'scheduled')
                    ->where('start_time', '>=', now())
                    ->with(['student', 'classRequest.subject'])
                    ->orderBy('start_time')
                    ->take(5)->get() : [],
                'pending_requests' => $profile ? (function () use ($profile) {
                    $offerIds   = $profile->classOffers()->pluck('id');
                    $subjectIds = $profile->subjects()->pluck('subjects.id');
                    return ClassRequest::where('status', 'open')
                        ->where(function ($q) use ($offerIds, $subjectIds) {
                            $q->whereIn('class_offer_id', $offerIds)
                              ->orWhere(function ($inner) use ($subjectIds) {
                                  $inner->whereNull('class_offer_id')
                                        ->whereIn('subject_id', $subjectIds);
                              });
                        })->count();
                })() : 0,
            ]);
        }

        $studentIds = $user->students()->pluck('id');
        return Inertia::render('Dashboard/Parent', [
            'students' => $user->students()->get(),
            'upcoming' => Lesson::whereIn('student_id', $studentIds)
                ->where('status', 'scheduled')
                ->where('start_time', '>=', now())
                ->with(['teacherProfile.user', 'student', 'classRequest.subject'])
                ->orderBy('start_time')
                ->take(5)->get(),
            'pending_approval' => ClassRequest::whereIn('student_id', $studentIds)
                ->where('status', 'pending_parent_approval')
                ->count(),
        ]);
    }
}
