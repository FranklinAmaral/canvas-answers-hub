<?php

declare(strict_types=1);

namespace App\Services\AiGrader;

use App\Models\AiGraderAiProvider;
use App\Models\AiGraderBlueprintConfig;
use App\Models\AiGraderCorrectionItem;
use App\Services\AiGrader\Data\AiGraderResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class AiGraderPendingProcessor
{
    public function __construct(
        private readonly AiGraderCorrectionEvaluator $evaluator,
        private readonly AiGraderQuotaService $quotaService,
        private readonly AiGraderSanitizer $sanitizer,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function process(array $filters = []): array
    {
        $dryRun = (bool) ($filters['dry_run'] ?? false);
        $providerOverride = $this->providerOverride($filters);
        $items = $this->eligibleQuery($filters)->get();
        $summary = $this->emptySummary($items->count(), $dryRun);

        foreach ($items as $item) {
            $row = $this->processItem($item, $dryRun, $providerOverride);

            $summary['items'][] = $row;

            if ($row['result'] === 'success') {
                $summary['processed_success']++;
            }

            if ($row['new_status'] === AiGraderCorrectionItem::STATUS_PENDING_TEACHER_APPROVAL) {
                $summary['pending_teacher_approval']++;
            }

            if ($row['new_status'] === AiGraderCorrectionItem::STATUS_AI_CORRECTED) {
                $summary['ai_corrected']++;
            }

            if ($row['new_status'] === AiGraderCorrectionItem::STATUS_AI_REJECTED) {
                $summary['ai_rejected']++;
            }

            if ($row['new_status'] === AiGraderCorrectionItem::STATUS_FAILED) {
                $summary['failed']++;
            }

            if ($row['quota_action'] === 'consumed') {
                $summary['quota_consumed']++;
            }

            if ($row['quota_action'] === 'released') {
                $summary['quota_released']++;
            }
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function eligibleQuery(array $filters): Builder
    {
        $query = AiGraderCorrectionItem::query()
            ->with(['organization', 'blueprintConfig.aiProvider'])
            ->where('status', AiGraderCorrectionItem::STATUS_PENDING);

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', (int) $filters['organization_id']);
        }

        if (! empty($filters['environment_id'])) {
            $query->where('canvas_environment_id', (int) $filters['environment_id']);
        }

        if (! empty($filters['blueprint_config_id'])) {
            $query->where('ai_grader_blueprint_config_id', (int) $filters['blueprint_config_id']);
        }

        if (! empty($filters['quiz_config_id'])) {
            $query->where('ai_grader_quiz_config_id', (int) $filters['quiz_config_id']);
        }

        if (! empty($filters['item_id'])) {
            $query->whereKey((int) $filters['item_id']);
        }

        $query->orderBy('created_at')->orderBy('id');

        if (! empty($filters['limit']) && (int) $filters['limit'] > 0) {
            $query->limit((int) $filters['limit']);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function processItem(AiGraderCorrectionItem $item, bool $dryRun, ?AiGraderAiProvider $providerOverride = null): array
    {
        $oldStatus = (string) $item->status;

        try {
            $result = $this->evaluator->evaluate($item, $providerOverride);

            if ($result->rejected) {
                $newStatus = $this->isTechnicalRejection($result)
                    ? AiGraderCorrectionItem::STATUS_FAILED
                    : AiGraderCorrectionItem::STATUS_AI_REJECTED;

                $quotaAction = $dryRun ? 'dry-run' : 'released';

                if (! $dryRun) {
                    DB::transaction(function () use ($item, $result, $newStatus, $providerOverride): void {
                        $item->forceFill([
                            'status' => $newStatus,
                            'failure_reason' => $result->rejectionReason,
                            'ai_raw_response' => $this->sanitizer->sanitizeArray($result->rawResponse),
                            'failed_at' => $newStatus === AiGraderCorrectionItem::STATUS_FAILED ? now() : null,
                        ])->save();

                        $usageStatus = $newStatus === AiGraderCorrectionItem::STATUS_FAILED ? 'failed' : 'rejected';

                        $this->quotaService->release($item, $this->usageAttributes($item, $result, $usageStatus, $providerOverride));
                    });
                }

                return $this->row($item, $oldStatus, $newStatus, $result, $quotaAction, $newStatus === AiGraderCorrectionItem::STATUS_FAILED ? 'failed' : 'rejected');
            }

            $newStatus = $this->successStatus($item);
            $quotaAction = $dryRun ? 'dry-run' : 'consumed';

            if (! $dryRun) {
                DB::transaction(function () use ($item, $result, $newStatus, $providerOverride): void {
                    $publicationMode = AiGraderBlueprintConfig::normalizePublicationMode($item->publication_mode);

                    $item->forceFill([
                        'publication_mode' => $publicationMode,
                        'ai_score' => $result->score,
                        'ai_rubric_percentage' => $result->rubricPercentage,
                        'ai_feedback' => $result->feedback,
                        'ai_rubric_feedback' => $this->sanitizer->sanitizeArray($result->rubricFeedback),
                        'ai_corrected_at' => now(),
                        'ai_provider_type' => $providerOverride?->provider_type ?? $this->providerType($item, $result),
                        'ai_provider_model' => $providerOverride?->model ?? $this->providerModel($item, $result),
                        'ai_confidence' => $result->confidence,
                        'ai_review_flags' => $this->sanitizer->sanitizeArray($result->reviewFlags),
                        'ai_raw_response' => $this->sanitizer->sanitizeArray($result->rawResponse),
                        'final_score' => null,
                        'final_feedback' => null,
                        'teacher_rubric_feedback' => null,
                        'review_status' => $publicationMode === AiGraderBlueprintConfig::PUBLICATION_TEACHER_APPROVAL
                            ? AiGraderCorrectionItem::REVIEW_STATUS_PENDING
                            : null,
                        'reviewed_at' => null,
                        'reviewer_canvas_user_id' => null,
                        'reviewer_name' => null,
                        'reviewer_login_id' => null,
                        'published_rubric_feedback' => null,
                        'canvas_publication_error' => null,
                        'failure_reason' => null,
                        'failed_at' => null,
                        'status' => $newStatus,
                    ])->save();

                    $this->quotaService->consume($item, $this->usageAttributes($item, $result, 'success', $providerOverride));
                });
            }

            return $this->row($item, $oldStatus, $newStatus, $result, $quotaAction, 'success');
        } catch (Throwable $exception) {
            $newStatus = AiGraderCorrectionItem::STATUS_FAILED;
            $reason = $this->failureReason($exception);

            if (! $dryRun) {
                $this->markFailed($item, $reason);
                $this->releaseQuotaAfterFailure($item);
            }

            return [
                'item_id' => $item->id,
                'student' => $item->canvas_user_name ?: $item->canvas_user_id,
                'question_id' => $item->canvas_question_id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'ai_score' => '-',
                'points_possible' => $item->canvas_points_possible,
                'feedback_sample' => $reason,
                'quota_action' => $dryRun ? 'dry-run' : 'released',
                'result' => 'failed',
            ];
        }
    }

    private function successStatus(AiGraderCorrectionItem $item): string
    {
        return match (AiGraderBlueprintConfig::normalizePublicationMode($item->publication_mode)) {
            AiGraderBlueprintConfig::PUBLICATION_AUTO_PUBLISH => AiGraderCorrectionItem::STATUS_AI_CORRECTED,
            default => AiGraderCorrectionItem::STATUS_PENDING_TEACHER_APPROVAL,
        };
    }

    private function isTechnicalRejection(AiGraderResult $result): bool
    {
        return in_array($result->rejectionReason, [
            'invalid_status',
            'missing_answer',
            'missing_grading_instructions',
            'invalid_points_possible',
            'connection_error',
            'timeout',
            'network_error',
            'http_error',
            'http_429',
            'http_500',
            'http_502',
            'http_503',
            'http_504',
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function usageAttributes(AiGraderCorrectionItem $item, AiGraderResult $result, string $status, ?AiGraderAiProvider $providerOverride = null): array
    {
        return [
            'ai_provider_id' => $providerOverride?->id ?? $this->rawProviderId($result) ?? $item->blueprintConfig?->ai_provider_id,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'total_tokens' => $result->totalTokens,
            'estimated_cost' => $result->estimatedCost,
            'status' => $status,
            'metadata' => [
                'ai_confidence' => $result->confidence,
                'rejected' => $result->rejected,
                'rejection_reason' => $result->rejectionReason,
                'provider' => $providerOverride?->provider_type ?? $this->providerType($item, $result),
            ],
        ];
    }

    private function providerType(AiGraderCorrectionItem $item, ?AiGraderResult $result = null): string
    {
        return (string) data_get(
            $result?->rawResponse,
            'provider',
            $item->blueprintConfig?->aiProvider?->provider_type ?? '',
        );
    }

    private function providerModel(AiGraderCorrectionItem $item, ?AiGraderResult $result = null): ?string
    {
        $model = data_get($result?->rawResponse, 'model')
            ?? data_get($result?->rawResponse, 'body.model');

        $model ??= $item->blueprintConfig?->aiProvider?->model;

        return is_scalar($model) && trim((string) $model) !== ''
            ? trim((string) $model)
            : null;
    }

    private function rawProviderId(AiGraderResult $result): ?int
    {
        $value = data_get($result->rawResponse, 'ai_provider_id');

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function providerOverride(array $filters): ?AiGraderAiProvider
    {
        if (empty($filters['provider_id'])) {
            return null;
        }

        return AiGraderAiProvider::query()
            ->whereKey((int) $filters['provider_id'])
            ->firstOrFail();
    }

    private function markFailed(AiGraderCorrectionItem $item, string $reason): void
    {
        $item->forceFill([
            'status' => AiGraderCorrectionItem::STATUS_FAILED,
            'failure_reason' => $reason,
            'failed_at' => now(),
        ])->save();
    }

    private function releaseQuotaAfterFailure(AiGraderCorrectionItem $item): void
    {
        try {
            $this->quotaService->release($item, [
                'status' => 'failed',
                'metadata' => [
                    'reason' => 'technical_failure',
                ],
            ]);
        } catch (Throwable) {
            // Failure status is more important than masking the original processor error with a quota error.
        }
    }

    private function failureReason(Throwable $exception): string
    {
        return mb_substr($exception->getMessage(), 0, 500);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(AiGraderCorrectionItem $item, string $oldStatus, string $newStatus, AiGraderResult $result, string $quotaAction, string $rowResult): array
    {
        return [
            'item_id' => $item->id,
            'student' => $item->canvas_user_name ?: $item->canvas_user_id,
            'question_id' => $item->canvas_question_id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'ai_score' => $result->rejected ? '-' : $result->score,
            'points_possible' => $item->canvas_points_possible,
            'feedback_sample' => $this->feedbackSample($result),
            'quota_action' => $quotaAction,
            'result' => $rowResult,
        ];
    }

    private function feedbackSample(AiGraderResult $result): string
    {
        $value = $result->rejected
            ? (string) $result->rejectionReason
            : $result->feedback;

        return mb_strlen($value) > 80 ? mb_substr($value, 0, 77) . '...' : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(int $itemsFound, bool $dryRun): array
    {
        return [
            'items_found' => $itemsFound,
            'processed_success' => 0,
            'pending_teacher_approval' => 0,
            'ai_corrected' => 0,
            'ai_rejected' => 0,
            'failed' => 0,
            'quota_consumed' => 0,
            'quota_released' => 0,
            'dry_run' => $dryRun,
            'items' => [],
        ];
    }
}
