<?php

namespace App\Providers;

use App\Services\Overdue\OverdueServiceClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OverdueServiceClient::class, fn () => new OverdueServiceClient(
            baseUrl: (string) config('services.overdue.url'),
            token: config('services.overdue.token'),
            timeout: (int) config('services.overdue.timeout', 5),
        ));
    }

    public function boot(): void
    {
        // Catch N+1 queries and silently-dropped attributes during development/tests.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Resources are wrapped by ApiResponse, not by Laravel's default "data" key.
        JsonResource::withoutWrapping();

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }
}
