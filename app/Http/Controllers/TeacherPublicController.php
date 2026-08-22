<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\TeacherProfile;
use App\Models\TeacherReview;
use Inertia\Inertia;

class TeacherPublicController extends Controller
{
    public function show(TeacherProfile $teacherProfile)
    {
        abort_unless($teacherProfile->is_verified, 404);

        $teacherProfile->load([
            'user:id,name,avatar_url',
            'subjects:id,name,level',
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
                'avatar_url'       => $teacherProfile->user->avatar_url,
                'bio'              => $teacherProfile->bio,
                'hourly_rate'      => $teacherProfile->hourly_rate,
                'is_verified'      => $teacherProfile->is_verified,
                'subjects'         => $teacherProfile->subjects,
                'classes_completed'=> $classesCompleted,
                'avg_rating'       => $teacherProfile->avgRating(),
                'review_count'     => $teacherProfile->reviewCount(),
                'reviews'          => $reviews,
                // El código NUNCA viaja para un visitante cualquiera — solo
                // para el propio profesor (para que lo encuentre y lo
                // comparta) o para un padre que ya tuvo al menos una clase
                // completada con él (para repetir). No requiere que el
                // padre haya usado el código la primera vez: la relación se
                // prueba con Lesson.teacher_profile_id, no con
                // ClassRequest.teacher_referral_code.
                'referral_code'    => $this->referralCodeVisibleTo($teacherProfile) ? $teacherProfile->referral_code : null,
            ],
        ]);
    }

    private function referralCodeVisibleTo(TeacherProfile $teacherProfile): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        if ($teacherProfile->user_id === $user->id) {
            return true;
        }

        return Lesson::where('teacher_profile_id', $teacherProfile->id)
            ->where('status', 'completed')
            ->whereHas('student', fn ($q) => $q->where('parent_user_id', $user->id))
            ->exists();
    }
}
