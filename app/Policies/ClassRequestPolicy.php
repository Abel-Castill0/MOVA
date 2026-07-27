<?php

namespace App\Policies;

use App\Models\ClassRequest;
use App\Models\User;

class ClassRequestPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('admin') ? true : null;
    }

    // Parent approving/rejecting their own child's request (matches routes
    // class-requests.approve / class-requests.reject).
    public function view(User $user, ClassRequest $classRequest): bool
    {
        return $user->students()->whereKey($classRequest->student_id)->exists();
    }

    // Teacher accepting an open request (matches route teacher.requests.accept).
    public function accept(User $user, ClassRequest $classRequest): bool
    {
        $profile = $user->teacherProfile;
        if (! $profile) {
            return false;
        }

        $offerIds = $profile->classOffers()->pluck('id');
        $subjectIds = $profile->subjects()->pluck('subjects.id');
        $ownedViaOffer = $classRequest->class_offer_id && $offerIds->contains($classRequest->class_offer_id);
        $ownedViaSubject = ! $classRequest->class_offer_id && $subjectIds->contains($classRequest->subject_id);

        return $ownedViaOffer || $ownedViaSubject;
    }

    // Teacher rejecting an open request (matches route teacher.requests.reject).
    public function reject(User $user, ClassRequest $classRequest): bool
    {
        return $this->accept($user, $classRequest);
    }
}
