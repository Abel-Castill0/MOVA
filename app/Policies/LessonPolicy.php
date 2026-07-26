<?php

namespace App\Policies;

use App\Models\Lesson;
use App\Models\User;

class LessonPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('admin') ? true : null;
    }

    public function view(User $user, Lesson $lesson): bool
    {
        $profile = $user->teacherProfile;
        $isTeacher = $profile && $lesson->teacher_profile_id === $profile->id;
        $isParent = $lesson->student && $lesson->student->parent_user_id === $user->id;

        return $isTeacher || $isParent;
    }

    public function cancel(User $user, Lesson $lesson): bool
    {
        $profile = $user->teacherProfile;
        $isTeacher = $profile && $lesson->teacher_profile_id === $profile->id;
        $isParent = $user->hasRole('parent') && $user->students()->where('id', $lesson->student_id)->exists();

        return $isTeacher || $isParent;
    }

    public function reschedule(User $user, Lesson $lesson): bool
    {
        $profile = $user->teacherProfile;
        $isTeacher = $profile && $lesson->teacher_profile_id === $profile->id;
        $isParent = $user->hasRole('parent') && $user->students()->where('id', $lesson->student_id)->exists();

        return $isTeacher || $isParent;
    }

    public function confirmPayment(User $user, Lesson $lesson): bool
    {
        return $user->hasRole('parent') && $user->students()->where('id', $lesson->student_id)->exists();
    }

    public function createReport(User $user, Lesson $lesson): bool
    {
        $profile = $user->teacherProfile;

        return $profile && $lesson->teacher_profile_id === $profile->id;
    }

    public function createReview(User $user, Lesson $lesson): bool
    {
        return $user->hasRole('parent') && $user->students()->where('id', $lesson->student_id)->exists();
    }
}
