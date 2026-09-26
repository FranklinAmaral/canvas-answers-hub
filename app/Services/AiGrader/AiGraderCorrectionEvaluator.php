<?php

declare(strict_types=1);

namespace App\Services\AiGrader;

use App\Models\AiGraderCorrectionItem;
use App\Models\AiGraderAiProvider;
use App\Services\AiGrader\Data\AiGraderResult;

class AiGraderCorrectionEvaluator
{
    public function __construct(
        private readonly AiGraderPromptBuilder $promptBuilder,
        private readonly AiGraderProviderResolver $providerResolver,
        private readonly AiGraderScoreNormalizer $scoreNormalizer,
    ) {
    }

    public function evaluate(AiGraderCorrectionItem $item, ?AiGraderAiProvider $providerOverride = null): AiGraderResult
    {
        $rejectionReason = $this->rejectionReasonFor($item);

        if ($rejectionReason !== null) {
            return AiGraderResult::rejected($rejectionReason, [
                'validator' => self::class,
            ]);
        }

        $context = $this->promptBuilder->contextFor($item);
        $result = $this->providerResolver
            ->resolveForItem($item, $providerOverride)
            ->grade($item, $context);

        if ($result->rejected) {
            return $result;
        }

        return $result->withScore(
            $this->scoreNormalizer->normalize($result->score, $context->pointsPossible),
        );
    }

    private function rejectionReasonFor(AiGraderCorrectionItem $item): ?string
    {
        if ($item->status !== AiGraderCorrectionItem::STATUS_PENDING) {
            return 'invalid_status';
        }

        if (trim((string) $item->answer_text) === '') {
            return 'missing_answer';
        }

        if (trim((string) $item->ai_grading_instructions_snapshot) === '') {
            return 'missing_grading_instructions';
        }

        if ((float) $item->canvas_points_possible <= 0) {
            return 'invalid_points_possible';
        }

        return null;
    }
}
