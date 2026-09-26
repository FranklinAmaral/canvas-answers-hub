<?php

declare(strict_types=1);

namespace App\Services\AiGrader;

use RuntimeException;

class AiGraderLtiSecurityException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 401,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
