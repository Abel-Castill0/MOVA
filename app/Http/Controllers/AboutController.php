<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\TeacherProfile;
use App\Models\User;
use Inertia\Inertia;

class AboutController extends Controller
{
    public function index()
    {
        return Inertia::render('About', [
            'stats' => [
                'teachers' => TeacherProfile::where('is_verified', true)->count(),
                'classes' => Lesson::where('status', 'completed')->count(),
                'students' => User::role('parent')->count(),
            ],
        ]);
    }
}
