<?php

namespace App\Http\Controllers;

use App\Models\TeacherProfile;
use App\Models\TeacherReview;
use Inertia\Inertia;

class TeacherPublicController extends Controller
{
    public function show(TeacherProfile $teacherProfile)
    {
        abort_unless($teacherProfile->is_verified, 404);

        $teacherProfile->load([
            'user:id,name',
            'subjects:id,name,level',
            'classOffers' => fn($q) => $q->where('is_active', true)
                ->with('subject:id,name,level')
                ->latest(),
        ]);

        // Count completed classes (from classes table)
        $classesCompleted = \DB::table('classes')
            ->where('teacher_profile_id', $teacherProfile->id)
            ->where('status', 'completed')
            ->count();

        $reviews = TeacherReview::where('teacher_profile_id', $teacherProfile->id)
            ->where('is_visible', true)
            ->latest()
            ->take(5)
            ->get(['id', 'rating', 'comment', 'created_at']);

        return Inertia::render('Teachers/Show', [
            'teacher'          => [
                'id'               => $teacherProfile->id,
                'name'             => $teacherProfile->user->name,
                'bio'              => $teacherProfile->bio,
                'hourly_rate'      => $teacherProfile->hourly_rate,
                'is_verified'      => $teacherProfile->is_verified,
                'subjects'         => $teacherProfile->subjects,
                'offers'           => $teacherProfile->classOffers,
                'classes_completed'=> $classesCompleted,
                'avg_rating'       => $teacherProfile->avgRating(),
                'review_count'     => $teacherProfile->reviewCount(),
                'reviews'          => $reviews,
            ],
        ]);
    }
}
