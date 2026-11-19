<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // results are scoped by Task::visibleTo()
    }

    public function view(User $user, Task $task): bool
    {
        return $user->isAdmin() || $task->assigned_to === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /** Editing title, description, priority, due date or assignee. */
    public function update(User $user, Task $task): bool
    {
        return $user->isAdmin();
    }

    /** Members may change the status of their own tasks; the overdue rules still apply. */
    public function updateStatus(User $user, Task $task): bool
    {
        return $user->isAdmin() || $task->assigned_to === $user->id;
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->isAdmin();
    }
}
