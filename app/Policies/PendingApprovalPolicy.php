<?php

namespace App\Policies;

use App\Models\PendingApproval;
use App\Models\User;

class PendingApprovalPolicy
{
    public function decide(User $user, PendingApproval $approval): bool
    {
        return $approval->task?->user_id === $user->id;
    }
}
