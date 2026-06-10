<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Inertia\Inertia;

class WelcomeController extends Controller
{
    public function index()
    {
        return Inertia::render('Welcome', [
            'subjects' => Subject::orderBy('name')->get(['id', 'name', 'level']),

            'featuredTeachers' => TeacherProfile::where('is_verified', true)
                ->with(['user:id,name', 'subjects:id,name'])
                ->withCount('classes')
                ->orderByDesc('classes_count')
                ->limit(6)
                ->get(['id', 'user_id', 'bio', 'hourly_rate']),

            'stats' => [
                'teachers'  => TeacherProfile::where('is_verified', true)->count(),
                'students'  => User::role('parent')->count(),
                'completed' => Lesson::where('status', 'completed')->count(),
            ],
        ]);
    }
}
