<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class ProjectService
{
    public function paginate(User $user, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return Project::query()
            ->visibleTo($user)
            ->with('creator:id,name,email')
            ->withCount($this->taskCounts($user))
            ->when($filters['search'] ?? null, fn (Builder $q, $v) => $q->where('name', 'like', '%'.$v.'%'))
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Load everything the "project details" screen needs in a fixed number of
     * queries (no N+1): creator, task counts, tasks + their assignee.
     */
    public function loadDetails(Project $project, User $user): Project
    {
        return $project
            ->load([
                'creator:id,name,email',
                'tasks' => fn ($q) => $q->visibleTo($user)
                    ->with(['assignee:id,name,email', 'creator:id,name,email'])
                    ->orderBy('due_date'),
            ])
            ->loadCount($this->taskCounts($user));
    }

    public function create(User $creator, array $data): Project
    {
        return $creator->createdProjects()->create($data)->load('creator:id,name,email');
    }

    public function update(Project $project, array $data): Project
    {
        $project->update($data);

        return $project->load('creator:id,name,email');
    }

    public function delete(Project $project): void
    {
        $project->delete();
    }

    /** Counts are scoped to the tasks the user is allowed to see. */
    private function taskCounts(User $user): array
    {
        $scope = fn (Builder $q) => $q->visibleTo($user);

        return [
            'tasks' => $scope,
            'tasks as done_tasks_count' => fn (Builder $q) => $scope($q)->where('status', TaskStatus::Done),
            // Same rule as Task::isOverdue(): OVERDUE, or past due and not DONE (sweep not run yet).
            'tasks as overdue_tasks_count' => fn (Builder $q) => $scope($q)->where(fn (Builder $w) => $w
                ->where('status', TaskStatus::Overdue)
                ->orWhere(fn (Builder $late) => $late
                    ->where('status', '!=', TaskStatus::Done)
                    ->whereDate('due_date', '<', today()))),
        ];
    }
}
