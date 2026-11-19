<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Task\IndexTaskRequest;
use App\Http\Requests\Task\StoreTaskRequest;
use App\Http\Requests\Task\UpdateTaskRequest;
use App\Http\Requests\Task\UpdateTaskStatusRequest;
use App\Http\Resources\TaskResource;
use App\Http\Responses\ApiResponse;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class TaskController extends Controller
{
    public function __construct(private readonly TaskService $tasks) {}

    public function index(IndexTaskRequest $request): JsonResponse
    {
        $filters = $request->safe()->except('per_page');
        $paginator = $this->tasks->paginate($request->user(), $filters, (int) $request->validated('per_page', 20));

        return ApiResponse::paginated($paginator, TaskResource::class);
    }

    public function store(StoreTaskRequest $request): JsonResponse
    {
        $task = $this->tasks->create($request->user(), $request->validated());

        return ApiResponse::success(new TaskResource($task), 'Task created.', 201);
    }

    public function show(Task $task): JsonResponse
    {
        Gate::authorize('view', $task);

        return ApiResponse::success(new TaskResource($this->tasks->loadDetails($task)));
    }

    public function update(UpdateTaskRequest $request, Task $task): JsonResponse
    {
        $task = $this->tasks->update($task, $request->validated());

        return ApiResponse::success(new TaskResource($task), 'Task updated.');
    }

    public function updateStatus(UpdateTaskStatusRequest $request, Task $task): JsonResponse
    {
        $task = $this->tasks->changeStatus($task, $request->targetStatus(), $request->user());

        return ApiResponse::success(new TaskResource($task), 'Task status updated.');
    }

    public function destroy(Task $task): JsonResponse
    {
        Gate::authorize('delete', $task);

        $this->tasks->delete($task);

        return ApiResponse::success(null, 'Task deleted.');
    }
}
