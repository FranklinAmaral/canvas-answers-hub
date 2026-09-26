<?php

declare(strict_types=1);

namespace App\Services\AiGrader;

use App\Jobs\AiGrader\AiGraderRetryCorrectionItemJob;
use App\Models\AiGraderBlueprintConfig;
use App\Models\AiGraderCorrectionItem;
use Illuminate\Support\Facades\DB;

class AiGraderCorrectionRetryService
{
    public const RETRY_AI = 'ai';
    public const RETRY_PUBLICATION = 'publication';

    /**
     * @param array<string, mixed> $metadata
     * @return array{queued: bool, retry_type: string|null}
     */
    public function retryAndDispatch(AiGraderCorrectionItem $item, array $metadata = []): array
    {
        $retryType = $this->prepareRetry($item);

        if ($retryType === null) {
            return [
                'queued' => false,
                'retry_type' => null,
            ];
        }

        dispatch(new AiGraderRetryCorrectionItemJob($item->id, $retryType, $metadata));

        return [
            'queued' => true,
            'retry_type' => $retryType,
        ];
    }

    /**
     * @param iterable<int, AiGraderCorrectionItem> $items
     * @param array<string, mixed> $metadata
     * @return array{queued: int, ai: int, publication: int, ignored: int}
     */
    public function retryManyAndDispatch(iterable $items, array $metadata = []): array
    {
        $summary = [
            'queued' => 0,
            'ai' => 0,
            'publication' => 0,
            'ignored' => 0,
        ];

        foreach ($items as $item) {
            $result = $this->retryAndDispatch($item, $metadata);

            if (! $result['queued']) {
                $summary['ignored']++;

                continue;
            }

            $summary['queued']++;

            if ($result['retry_type'] === self::RETRY_PUBLICATION) {
                $summary['publication']++;

                continue;
            }

            $summary['ai']++;
        }

        return $summary;
    }

    public function retryLabel(string $retryType): string
    {
        return $retryType === self::RETRY_PUBLICATION
            ? 'publication'
            : 'correction';
    }

    private function prepareRetry(AiGraderCorrectionItem $item): ?string
    {
        return match ($item->status) {
            AiGraderCorrectionItem::STATUS_FAILED => $this->resetAiFailure($item),
            AiGraderCorrectionItem::STATUS_PUBLICATION_FAILED => $this->resetPublicationFailure($item),
            default => null,
        };
    }

    private function resetAiFailure(AiGraderCorrectionItem $item): string
    {
        DB::transaction(function () use ($item): void {
            $item->forceFill([
                'status' => AiGraderCorrectionItem::STATUS_PENDING,
                'ai_score' => null,
                'ai_rubric_percentage' => null,
                'ai_feedback' => null,
                'ai_rubric_feedback' => null,
                'ai_corrected_at' => null,
                'ai_provider_type' => null,
                'ai_provider_model' => null,
                'ai_raw_response' => null,
                'ai_confidence' => null,
                'ai_review_flags' => null,
                'final_score' => null,
                'final_feedback' => null,
                'teacher_rubric_feedback' => null,
                'review_status' => null,
                'reviewed_at' => null,
                'reviewer_canvas_user_id' => null,
                'reviewer_name' => null,
                'reviewer_login_id' => null,
                'teacher_user_id' => null,
                'teacher_adjusted_at' => null,
                'canvas_published_score' => null,
                'canvas_published_feedback' => null,
                'published_rubric_feedback' => null,
                'published_at' => null,
                'canvas_publication_error' => null,
                'failure_reason' => null,
                'failed_at' => null,
            ])->save();
        });

        return self::RETRY_AI;
    }

    private function resetPublicationFailure(AiGraderCorrectionItem $item): string
    {
        DB::transaction(function () use ($item): void {
            $item->forceFill([
                'status' => $this->publicationRetryStatus($item),
                'canvas_publication_error' => null,
                'failure_reason' => null,
                'failed_at' => null,
            ])->save();
        });

        return self::RETRY_PUBLICATION;
    }

    private function publicationRetryStatus(AiGraderCorrectionItem $item): string
    {
        if (AiGraderBlueprintConfig::normalizePublicationMode($item->publication_mode) === AiGraderBlueprintConfig::PUBLICATION_AUTO_PUBLISH) {
            return AiGraderCorrectionItem::STATUS_AI_CORRECTED;
        }

        if ($item->published_at !== null) {
            return AiGraderCorrectionItem::STATUS_ADJUSTED_BY_TEACHER;
        }

        if (in_array($item->review_status, [
            AiGraderCorrectionItem::REVIEW_STATUS_APPROVED,
            AiGraderCorrectionItem::REVIEW_STATUS_PUBLISHED,
        ], true)) {
            return AiGraderCorrectionItem::STATUS_APPROVED_BY_TEACHER;
        }

        return AiGraderCorrectionItem::STATUS_PENDING_TEACHER_APPROVAL;
    }
}
