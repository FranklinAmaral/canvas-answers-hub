<?php

declare(strict_types=1);

namespace App\Services\AiGrader;

use App\Models\AiGraderCorrectionItem;
use App\Models\AiGraderTeacherAction;
use App\Support\AiGraderLtiView;
use Illuminate\Support\Facades\DB;

class AiGraderTeacherReviewService
{
    public function __construct(
        private readonly AiGraderCanvasPublisher $publisher,
        private readonly AiGraderScoreNormalizer $scoreNormalizer,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $reviewer
     */
    public function saveDraft(AiGraderCorrectionItem $item, array $payload, array $reviewer): AiGraderCorrectionItem
    {
        [$score, $feedback] = $this->resolvedReviewValues($item, $payload);
        $rubricFeedback = $this->resolvedRubricFeedback($item, $payload);
        $previousScore = $item->final_score ?? $item->ai_score;
        $previousFeedback = $item->final_feedback ?? $item->ai_feedback;

        DB::transaction(function () use ($item, $score, $feedback, $rubricFeedback, $reviewer): void {
            $item->forceFill([
                'final_score' => $score,
                'final_feedback' => $feedback,
                'teacher_rubric_feedback' => $rubricFeedback,
                'review_status' => AiGraderCorrectionItem::REVIEW_STATUS_DRAFT,
                'reviewed_at' => now(),
                'reviewer_canvas_user_id' => $reviewer['canvas_user_id'] ?? null,
                'reviewer_name' => $reviewer['name'] ?? null,
                'reviewer_login_id' => $reviewer['login'] ?? null,
                'teacher_user_id' => $reviewer['local_user_id'] ?? null,
                'teacher_adjusted_at' => now(),
                'status' => $item->status === AiGraderCorrectionItem::STATUS_PUBLISHED_TO_CANVAS
                    ? AiGraderCorrectionItem::STATUS_PUBLISHED_TO_CANVAS
                    : AiGraderCorrectionItem::STATUS_PENDING_TEACHER_APPROVAL,
            ])->save();
        });

        $this->logReviewChanges($item->fresh(), $reviewer, $previousScore, $score, $previousFeedback, $feedback);

        return $item->fresh();
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $reviewer
     * @return array<string, mixed>
     */
    public function publish(AiGraderCorrectionItem $item, array $payload, array $reviewer): array
    {
        $item = $this->saveDraft($item, $payload, $reviewer);
        $previousPublished = $item->status === AiGraderCorrectionItem::STATUS_PUBLISHED_TO_CANVAS;

        $item->forceFill([
            'review_status' => AiGraderCorrectionItem::REVIEW_STATUS_APPROVED,
            'reviewed_at' => now(),
            'status' => $previousPublished
                ? AiGraderCorrectionItem::STATUS_ADJUSTED_BY_TEACHER
                : AiGraderCorrectionItem::STATUS_APPROVED_BY_TEACHER,
        ])->save();

        $this->logAction(
            $item,
            $reviewer,
            AiGraderTeacherAction::ACTION_APPROVED,
            [
                'published_before' => $previousPublished,
            ]
        );

        $result = $this->publisher->publishTeacherReviewedItem($item->fresh());

        if (($result['result'] ?? null) === 'published') {
            $this->logAction(
                $item->fresh(),
                $reviewer,
                $previousPublished
                    ? AiGraderTeacherAction::ACTION_REPUBLISHED
                    : AiGraderTeacherAction::ACTION_PUBLISHED,
                [
                    'published_before' => $previousPublished,
                ]
            );
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: float, 1: string}
     */
    private function resolvedReviewValues(AiGraderCorrectionItem $item, array $payload): array
    {
        $rubricFeedback = $this->resolvedRubricFeedback($item, $payload);
        $rawScore = $this->rubricScore($item, $rubricFeedback)
            ?? ($payload['final_score'] ?? $item->final_score ?? $item->ai_score ?? 0);
        $score = $this->scoreNormalizer->normalize((float) $rawScore, (float) $item->canvas_points_possible);

        $feedback = trim((string) ($payload['final_feedback'] ?? $item->final_feedback ?? $item->ai_feedback ?? ''));

        return [$score, $feedback];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>|null
     */
    private function resolvedRubricFeedback(AiGraderCorrectionItem $item, array $payload): ?array
    {
        if (! $item->usesCanvasRubric()) {
            return null;
        }

        $raw = $payload['teacher_rubric_feedback']
            ?? $item->teacher_rubric_feedback
            ?? $item->ai_rubric_feedback
            ?? [];

        if (! is_array($raw)) {
            return $item->teacher_rubric_feedback;
        }

        $feedback = [];

        foreach ($raw as $criterion) {
            if (! is_array($criterion)) {
                continue;
            }

            $feedback[] = array_filter([
                'criterion_id' => $this->stringOrNull($criterion['criterion_id'] ?? null),
                'criterion_name' => $this->stringOrNull($criterion['criterion_name'] ?? null),
                'selected_rating' => $this->stringOrNull($criterion['selected_rating'] ?? null),
                'percentage' => is_numeric($criterion['percentage'] ?? null) ? (float) $criterion['percentage'] : null,
                'max_percentage' => is_numeric($criterion['max_percentage'] ?? null) ? (float) $criterion['max_percentage'] : null,
                'max_points' => is_numeric($criterion['max_points'] ?? null) ? (float) $criterion['max_points'] : null,
                'proportional_score' => AiGraderLtiView::criterionProportionalScore(
                    is_numeric($criterion['percentage'] ?? null) ? (float) $criterion['percentage'] : null,
                    is_numeric($item->canvas_points_possible) ? (float) $item->canvas_points_possible : null,
                ),
                'feedback' => trim((string) ($criterion['feedback'] ?? '')),
            ], fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return $feedback !== [] ? array_values($feedback) : null;
    }

    /**
     * @param array<int, array<string, mixed>>|null $rubricFeedback
     */
    private function rubricScore(AiGraderCorrectionItem $item, ?array $rubricFeedback): ?float
    {
        if (! $item->usesCanvasRubric() || ! is_array($rubricFeedback) || $rubricFeedback === []) {
            return null;
        }

        $weighted = 0.0;
        $hasWeights = false;

        foreach ($rubricFeedback as $criterion) {
            if (! is_array($criterion) || ! is_numeric($criterion['percentage'] ?? null) || ! is_numeric($criterion['max_percentage'] ?? null)) {
                continue;
            }

            $hasWeights = true;
            $weighted += ((float) $criterion['percentage'] * (float) $criterion['max_percentage']) / 100;
        }

        if (! $hasWeights || ! is_numeric($item->canvas_points_possible)) {
            return null;
        }

        return ($weighted / 100) * (float) $item->canvas_points_possible;
    }

    /**
     * @param array<string, mixed> $reviewer
     */
    private function logReviewChanges(
        AiGraderCorrectionItem $item,
        array $reviewer,
        mixed $previousScore,
        float $score,
        ?string $previousFeedback,
        string $feedback,
    ): void {
        if ((float) $previousScore !== $score) {
            $this->logAction($item, $reviewer, AiGraderTeacherAction::ACTION_ADJUSTED_SCORE, [
                'previous_score' => $previousScore,
                'new_score' => $score,
            ], $previousScore, $score, $previousFeedback, $feedback);
        }

        if (trim((string) $previousFeedback) !== $feedback) {
            $this->logAction($item, $reviewer, AiGraderTeacherAction::ACTION_ADJUSTED_FEEDBACK, [
                'previous_feedback' => $previousFeedback,
                'new_feedback' => $feedback,
            ], $previousScore, $score, $previousFeedback, $feedback);
        }
    }

    /**
     * @param array<string, mixed> $reviewer
     * @param array<string, mixed> $metadata
     */
    private function logAction(
        AiGraderCorrectionItem $item,
        array $reviewer,
        string $action,
        array $metadata = [],
        mixed $previousScore = null,
        mixed $newScore = null,
        ?string $previousFeedback = null,
        ?string $newFeedback = null,
    ): void {
        AiGraderTeacherAction::query()->create([
            'ai_grader_correction_item_id' => $item->id,
            'organization_id' => $item->organization_id,
            'actor_user_id' => $reviewer['local_user_id'] ?? null,
            'action' => $action,
            'previous_score' => is_numeric($previousScore) ? (float) $previousScore : null,
            'new_score' => is_numeric($newScore) ? (float) $newScore : null,
            'previous_feedback' => $previousFeedback,
            'new_feedback' => $newFeedback,
            'metadata' => array_filter([
                'reviewer_canvas_user_id' => $reviewer['canvas_user_id'] ?? null,
                'reviewer_name' => $reviewer['name'] ?? null,
                'reviewer_login_id' => $reviewer['login'] ?? null,
                'details' => $metadata,
            ], fn (mixed $value): bool => $value !== null && $value !== []),
            'created_at' => now(),
        ]);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
