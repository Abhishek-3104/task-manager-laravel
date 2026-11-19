<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The Django overdue service could not be reached or answered unexpectedly.
 * We fail closed (HTTP 503) rather than changing a status without the rules.
 */
class OverdueServiceUnavailableException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return ApiResponse::error(
            'The task rules service is temporarily unavailable. Please try again.',
            503,
        );
    }
}
