<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Exceptions\TaskTransitionException;
use App\Models\Task;
use App\Models\User;
use App\Services\Overdue\OverdueServiceClient;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TaskService
{
    private const RELATIONS = ['project:id,name', 'assignee:id,name,email', 'creator:id,name,email'];

    public function __construct(private readonly OverdueServiceClient $overdue) {}

    public function paginate(User $user, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return Task::query()
            ->visibleTo($user)
            ->filter($filters)
            ->with(self::RELATIONS)
            ->orderBy('due_date')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function loadDetails(Task $task): Task
    {
        return $task->load(self::RELATIONS);
    }

    public function create(User $creator, array $data): Task
    {
        $task = new Task($data);
        $task->creator()->associate($creator);
        $task->save();

        return $task->load(self::RELATIONS);
    }

    public function update(Task $task, array $data): Task
    {
        $task->update($data);

        return $task->load(self::RELATIONS);
    }

    public function delete(Task $task): void
    {
        $task->delete();
    }

    /**
     * Change a task's status after the Django overdue service approves it.
     *
     * The row is locked for the duration so two concurrent updates cannot both
     * pass validation against the same stale status. If the service reports
     * the task is effectively OVERDUE (sweep has not run yet), that status is
     * persisted even when the requested change is refused.
     *
     * @throws TaskTransitionException
     */
    public function changeStatus(Task $task, TaskStatus $target, User $actor): Task
    {
        $refusal = DB::transaction(function () use ($task, $target, $actor) {
            /** @var Task $locked */
            $locked = Task::whereKey($task->getKey())->lockForUpdate()->firstOrFail();

            $decision = $this->overdue->validateTransition(
                $locked->status,
                $target,
                $locked->due_date,
                $actor->role,
            );

            if (! $decision->allowed) {
                if ($decision->effectiveStatus === TaskStatus::Overdue && $locked->status !== TaskStatus::Overdue) {
                    $locked->forceFill(['status' => TaskStatus::Overdue])->save();
                }

                return $decision;
            }

            $locked->forceFill(['status' => $target])->save();

            return null;
        });

        if ($refusal !== null) {
            throw new TaskTransitionException($refusal->message, $refusal->code);
        }

        return $task->refresh()->load(self::RELATIONS);
    }
}
