<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Project */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'tasks_count' => $this->whenCounted('tasks'),
            'done_tasks_count' => $this->when(isset($this->done_tasks_count), fn () => (int) $this->done_tasks_count),
            'overdue_tasks_count' => $this->when(isset($this->overdue_tasks_count), fn () => (int) $this->overdue_tasks_count),
            'creator' => new UserResource($this->whenLoaded('creator')),
            'tasks' => TaskResource::collection($this->whenLoaded('tasks')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
