<?php

namespace App\Http\Requests\Task;

use App\Enums\TaskPriority;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Task::class);
    }

    /**
     * Status is intentionally not accepted here: every task starts as TODO and
     * only moves through PATCH /tasks/{task}/status (which enforces the rules).
     */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'due_date.after_or_equal' => 'The due date cannot be in the past.',
        ];
    }
}
