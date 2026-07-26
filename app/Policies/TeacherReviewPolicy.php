<?php

namespace App\Policies;

use App\Models\Lesson;
use App\Models\TeacherReview;
use App\Models\User;

class TeacherReviewPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('admin') ? true : null;
    }

    public function create(User $user, Lesson $lesson): bool
    {
        return $user->hasRole('parent') && $user->students()->where('id', $lesson->student_id)->exists();
    }

    public function moderate(User $user, TeacherReview $review): bool
    {
        // Only admins may moderate reviews; the before() hook grants that.
        return false;
    }
}
