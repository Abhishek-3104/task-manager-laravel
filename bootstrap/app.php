<?php

use App\Exceptions\OverdueServiceUnavailableException;
use App\Exceptions\TaskTransitionException;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Sanctum SPA auth: requests from the React app (SANCTUM_STATEFUL_DOMAINS)
        // get an encrypted, HttpOnly session cookie + CSRF protection on /api.
        $middleware->statefulApi();

        // API clients get a JSON 401 instead of a redirect to a login page.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $isApi = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        // Expected outcomes, not bugs: a refused status change is a normal 422, and
        // an unreachable Django service is already logged (with context) by the client.
        $exceptions->dontReport([
            TaskTransitionException::class,
            OverdueServiceUnavailableException::class,
        ]);

        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $isApi($request));

        // Every API error uses the same envelope as successful responses.
        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return ApiResponse::error($e->getMessage(), 422, $e->errors());
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return ApiResponse::error('Unauthenticated.', 401);
            }
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            $message = match (true) {
                // Don't leak model class names from ModelNotFoundException.
                $e instanceof NotFoundHttpException => 'Resource not found.',
                $e->getStatusCode() === 403 => $e->getMessage() ?: 'This action is unauthorized.',
                $e->getStatusCode() === 429 => 'Too many requests. Please slow down.',
                default => $e->getMessage() ?: 'Request failed.',
            };

            return ApiResponse::error($message, $e->getStatusCode())->withHeaders($e->getHeaders());
        });

        $exceptions->render(function (Throwable $e, Request $request) use ($isApi) {
            if ($isApi($request) && ! config('app.debug')) {
                return ApiResponse::error('Server error.', 500);
            }
        });
    })->create();
