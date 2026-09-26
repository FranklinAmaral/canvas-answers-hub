<?php

declare(strict_types=1);

namespace App\Services\Canvas;

use RuntimeException;

final class CanvasApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $method,
        public readonly string $endpoint,
        public readonly string $responseBody,
        public readonly int|string|null $environmentId,
        string $message,
    ) {
        parent::__construct($message, $status);
    }
}
