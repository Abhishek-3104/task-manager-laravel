<?php

namespace App\Services\Overdue;

use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Exceptions\OverdueServiceUnavailableException;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the Django "task-overdue-service".
 *
 * The Django service is the single owner of the overdue rules; Laravel asks it
 * before every status change instead of duplicating the logic.
 */
class OverdueServiceClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $token,
        private readonly int $timeout = 5,
    ) {}

    public function validateTransition(
        TaskStatus $current,
        TaskStatus $target,
        ?CarbonInterface $dueDate,
        UserRole $actorRole,
    ): TransitionDecision {
        $response = $this->send('transitions/validate/', [
            'current_status' => $current->value,
            'target_status' => $target->value,
            'due_date' => $dueDate?->toDateString(),
            'actor_role' => $actorRole->value,
        ]);

        $data = $response->json('data') ?? [];

        if ($response->successful() && ($data['allowed'] ?? false) === true) {
            return new TransitionDecision(
                allowed: true,
                effectiveStatus: TaskStatus::from($data['effective_status']),
                message: (string) $response->json('message', 'Transition allowed.'),
            );
        }

        if ($response->status() === 422 && isset($data['allowed'], $data['effective_status'])) {
            return new TransitionDecision(
                allowed: false,
                effectiveStatus: TaskStatus::from($data['effective_status']),
                message: (string) $response->json('message'),
                code: $data['code'] ?? null,
            );
        }

        $this->fail('Unexpected response from overdue service', $response);
    }

    /** Ask the service to mark every past-due, unfinished task as OVERDUE. */
    public function sync(): int
    {
        $response = $this->send('overdue/sync/', []);

        if (! $response->successful()) {
            $this->fail('Overdue sync failed', $response);
        }

        return (int) $response->json('data.updated', 0);
    }

    private function send(string $path, array $payload): Response
    {
        try {
            return $this->request()->post($path, $payload);
        } catch (ConnectionException $e) {
            Log::error('Overdue service unreachable', ['error' => $e->getMessage()]);
            throw new OverdueServiceUnavailableException($e->getMessage(), previous: $e);
        }
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/').'/api/v1/')
            ->acceptJson()
            ->asJson()
            ->withHeaders(['X-Service-Token' => (string) $this->token])
            ->timeout($this->timeout)
            // Retry only transient network failures, never 4xx/5xx answers.
            ->retry(2, 200, fn ($e) => $e instanceof ConnectionException, throw: false);
    }

    private function fail(string $message, Response $response): never
    {
        Log::error($message, ['status' => $response->status(), 'body' => $response->body()]);
        throw new OverdueServiceUnavailableException($message);
    }
}
