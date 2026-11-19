<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /** Lightweight list used by the "assign to" dropdown. */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $validated = $request->validate([
            'role' => ['nullable', Rule::enum(UserRole::class)],
        ]);

        $users = User::query()
            ->select(['id', 'name', 'email', 'role'])
            ->when($validated['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->orderBy('name')
            ->get();

        return ApiResponse::success(UserResource::collection($users));
    }
}
