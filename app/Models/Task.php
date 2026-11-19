<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    /** @use HasFactory<\Database\Factories\TaskFactory> */
    use HasFactory;

    /**
     * `status` and `created_by` are not mass assignable: status only changes
     * through TaskService::changeStatus(), which enforces the overdue rules.
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'assigned_to',
        'title',
        'description',
        'priority',
        'due_date',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'TODO',
        'priority' => 'MEDIUM',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'due_date' => 'date',
            'assigned_to' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isAdmin() ? $query : $query->where('assigned_to', $user->id);
    }

    /**
     * @param  array{status?: string, priority?: string, project_id?: int|string, search?: string}  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['priority'] ?? null, fn (Builder $q, $v) => $q->where('priority', $v))
            ->when($filters['project_id'] ?? null, fn (Builder $q, $v) => $q->where('project_id', $v))
            ->when($filters['search'] ?? null, fn (Builder $q, $v) => $q->where('title', 'like', '%'.$v.'%'));
    }

    /**
     * True when the task is (or should already be) OVERDUE. Lets the UI show
     * the correct state even between two runs of the overdue sweep.
     */
    public function isOverdue(): bool
    {
        if ($this->status === TaskStatus::Overdue) {
            return true;
        }

        return $this->status !== TaskStatus::Done
            && $this->due_date !== null
            && $this->due_date->lt(today());
    }
}
