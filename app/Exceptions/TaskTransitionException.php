<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Raised when the overdue service refuses a status change (HTTP 422). */
class TaskTransitionException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $reason = null)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return ApiResponse::error($this->getMessage(), 422, [
            'status' => [$this->getMessage()],
            'reason' => $this->reason,
        ]);
    }
}
