<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /** Admins list users to pick an assignee when creating tasks. */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
