<?php

namespace App\Services\Overdue;

use App\Enums\TaskStatus;

final readonly class TransitionDecision
{
    public function __construct(
        public bool $allowed,
        public TaskStatus $effectiveStatus,
        public string $message,
        public ?string $code = null,
    ) {}
}
