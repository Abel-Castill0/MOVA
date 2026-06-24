<?php

namespace App\Http\Controllers;

use App\Models\TeacherProfile;
use App\Models\User;
use App\Notifications\TeacherVerifiedNotification;
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
            'teachers' => TeacherProfile::where('is_verified', false)
                ->with(['user', 'subjects'])
                ->get(),
        ]);
    }

    public function verifyTeacher(TeacherProfile $teacher)
    {
        $teacher->update(['is_verified' => true]);
        $teacher->user->notify(new TeacherVerifiedNotification());
        return back()->with('success', 'Profesor verificado.');
    }

    public function rejectTeacher(TeacherProfile $teacher)
    {
        $teacher->user->delete();
        return back()->with('success', 'Profesor rechazado.');
    }
}
