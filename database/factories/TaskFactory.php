<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'assigned_to' => User::factory(),
            'created_by' => null,
            'title' => fake()->sentence(4),
            'description' => fake()->sentence(12),
            'status' => TaskStatus::Todo,
            'priority' => fake()->randomElement(TaskPriority::cases()),
            'due_date' => now()->addDays(fake()->numberBetween(1, 30))->toDateString(),
        ];
    }

    public function status(TaskStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function pastDue(int $days = 2): static
    {
        return $this->state(fn () => ['due_date' => now()->subDays($days)->toDateString()]);
    }

    public function overdue(): static
    {
        return $this->pastDue()->status(TaskStatus::Overdue);
    }
}
