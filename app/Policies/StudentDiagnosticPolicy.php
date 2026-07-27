<?php

namespace App\Policies;

use App\Models\StudentDiagnostic;
use App\Models\User;

class StudentDiagnosticPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('admin') ? true : null;
    }

    public function view(User $user, StudentDiagnostic $diagnostic): bool
    {
        return $diagnostic->parent_user_id === $user->id;
    }
}
