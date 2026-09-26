<?php

declare(strict_types=1);

namespace App\Services\AiGrader\Data;

class AiGraderPromptContext
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $questionText,
        public readonly string $answerText,
        public readonly float $pointsPossible,
        public readonly string $gradingInstructions,
        public readonly string $feedbackLanguage,
        public readonly string $correctionMode = 'simple',
        public readonly ?string $rubricTitle = null,
        public readonly array $rubricSnapshot = [],
        public readonly ?string $studentIdentifier = null,
        public readonly array $metadata = [],
    ) {
    }
}
