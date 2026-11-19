<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $this->ensureSession($request);

        $user = $this->auth->register($request->validated(), $request);

        return ApiResponse::success(new UserResource($user), 'Registration successful.', 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $this->ensureSession($request);

        $user = $this->auth->login(
            $request->validated('email'),
            $request->validated('password'),
            $request,
        );

        return ApiResponse::success(new UserResource($user), 'Login successful.');
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(new UserResource($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request);

        return ApiResponse::success(null, 'Logged out.');
    }

    /**
     * Sanctum only starts a session for requests coming from a trusted
     * frontend origin (SANCTUM_STATEFUL_DOMAINS). Give a clear error otherwise.
     */
    private function ensureSession(Request $request): void
    {
        abort_unless(
            $request->hasSession(),
            400,
            'Cookie session required. Call GET /sanctum/csrf-cookie first from an allowed frontend origin.',
        );
    }
}
