<?php

declare(strict_types=1);

namespace App\Services\AiGrader\Data;

use InvalidArgumentException;

class AiGraderResult
{
    /**
     * @param array<string, mixed> $reviewFlags
     * @param array<string, mixed> $rawResponse
     */
    public function __construct(
        public readonly float $score,
        public readonly string $feedback,
        public readonly ?float $rubricPercentage = null,
        public readonly array $rubricFeedback = [],
        public readonly ?string $confidence = null,
        public readonly array $reviewFlags = [],
        public readonly array $rawResponse = [],
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?int $totalTokens = null,
        public readonly ?float $estimatedCost = null,
        public readonly bool $rejected = false,
        public readonly ?string $rejectionReason = null,
    ) {
        if (! $this->rejected && trim($this->feedback) === '') {
            throw new InvalidArgumentException('AI Grader feedback cannot be empty for accepted results.');
        }
    }

    public static function rejected(string $reason, array $rawResponse = []): self
    {
        return new self(
            score: 0.0,
            feedback: '',
            rubricPercentage: null,
            rubricFeedback: [],
            rawResponse: $rawResponse,
            rejected: true,
            rejectionReason: $reason,
        );
    }

    public function withScore(float $score): self
    {
        return new self(
            score: $score,
            feedback: $this->feedback,
            rubricPercentage: $this->rubricPercentage,
            rubricFeedback: $this->rubricFeedback,
            confidence: $this->confidence,
            reviewFlags: $this->reviewFlags,
            rawResponse: $this->rawResponse,
            inputTokens: $this->inputTokens,
            outputTokens: $this->outputTokens,
            totalTokens: $this->totalTokens,
            estimatedCost: $this->estimatedCost,
            rejected: $this->rejected,
            rejectionReason: $this->rejectionReason,
        );
    }
}
