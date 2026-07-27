<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

class StudentPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('admin') ? true : null;
    }

    public function update(User $user, Student $student): bool
    {
        return $student->parent_user_id === $user->id;
    }

    public function delete(User $user, Student $student): bool
    {
        return $this->update($user, $student);
    }
}
