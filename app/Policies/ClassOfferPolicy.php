<?php

namespace App\Policies;

use App\Models\ClassOffer;
use App\Models\User;

class ClassOfferPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('admin') ? true : null;
    }

    public function update(User $user, ClassOffer $classOffer): bool
    {
        $profile = $user->teacherProfile;

        return $profile && $classOffer->teacher_profile_id === $profile->id;
    }

    public function delete(User $user, ClassOffer $classOffer): bool
    {
        return $this->update($user, $classOffer);
    }
}
