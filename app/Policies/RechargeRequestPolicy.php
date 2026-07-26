<?php

namespace App\Policies;

use App\Models\RechargeRequest;
use App\Models\User;

class RechargeRequestPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('admin') ? true : null;
    }

    public function approve(User $user, RechargeRequest $recharge): bool
    {
        // Only admins may approve recharges; the before() hook grants that.
        return false;
    }

    public function reject(User $user, RechargeRequest $recharge): bool
    {
        // Only admins may reject recharges; the before() hook grants that.
        return false;
    }
}
