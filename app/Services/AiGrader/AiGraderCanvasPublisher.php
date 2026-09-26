<?php

declare(strict_types=1);

namespace App\Services\AiGrader;

use App\Models\AiGraderBlueprintConfig;
use App\Models\AiGraderCanvasPublicationLog;
use App\Models\AiGraderCorrectionItem;
use App\Services\Canvas\CanvasApiClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Throwable;

class AiGraderCanvasPublisher
{
    public function __construct(
        private readonly AiGraderScoreNormalizer $scoreNormalizer,
        private readonly AiGraderSanitizer $sanitizer,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function publish(array $filters = []): array
    {
        $dryRun = (bool) ($filters['dry_run'] ?? false);
        $items = $this->candidateQuery($filters)->get();
        $summary = $this->emptySummary($items->count(), $dryRun);

        foreach ($items as $item) {
            $row = $this->publishItem($item, $dryRun, false);
            $summary['items'][] = $row;

            if ($row['result'] === 'published') {
                $summary['published']++;
            } elseif ($row['result'] === 'ignored') {
                $summary['ignored']++;
            } else {
                $summary['failed']++;
            }
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function publishTeacherReviewedItem(AiGraderCorrectionItem $item, bool $dryRun = false): array
    {
        $item->loadMissing(['canvasEnvironment', 'organization', 'blueprintConfig']);

        return $this->publishItem($item, $dryRun, true);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function candidateQuery(array $filters): Builder
    {
        $query = AiGraderCorrectionItem::query()
            ->with(['canvasEnvironment', 'organization', 'blueprintConfig'])
            ->when(empty($filters['item_id']), fn (Builder $query) => $query->where('status', AiGraderCorrectionItem::STATUS_AI_CORRECTED));

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
    private function publishItem(AiGraderCorrectionItem $item, bool $dryRun, bool $teacherInitiated): array
    {
        $oldStatus = (string) $item->status;
        $eligibilityError = $this->eligibilityError($item, $teacherInitiated);

        if ($eligibilityError !== null) {
            return $this->row($item, $oldStatus, (string) $item->status, '-', '-', '-', 'ignored', $eligibilityError);
        }

        $score = $this->scoreFor($item);
        $feedback = $this->feedbackFor($item);
        $rubricFeedback = $this->rubricFeedbackFor($item);
        $endpoint = $this->endpointFor($item);
        $payload = $this->payloadFor($item, $score, $feedback);

        if ($dryRun) {
            return $this->row($item, $oldStatus, (string) $item->status, $score, $endpoint, 'dry-run', 'ignored', $this->toJson($this->sanitizer->sanitizeArray($payload)));
        }

        $lastResponseStatus = null;
        $lastResponseBody = [];

        try {
            $client = new CanvasApiClient($item->canvasEnvironment);
            $response = $client->putResponse($endpoint, $payload);
            $responseBody = $this->responseBody($response);
            $lastResponseStatus = $response->status();
            $lastResponseBody = $responseBody;

            if (! $response->successful()) {
                $message = $this->httpFailureMessage($response);

                DB::transaction(function () use ($item, $endpoint, $payload, $response, $responseBody, $score, $feedback, $message): void {
                    $this->markFailed($item, $message);
                    $this->logPublication($item, $endpoint, $payload, $response->status(), $responseBody, false, $score, $feedback, $message);
                });

                return $this->row($item, $oldStatus, AiGraderCorrectionItem::STATUS_PUBLICATION_FAILED, $score, $endpoint, 'failed', 'failed', $message);
            }

            DB::transaction(function () use ($item, $endpoint, $payload, $response, $responseBody, $score, $feedback, $rubricFeedback, $teacherInitiated): void {
                $item->forceFill([
                    'final_score' => $item->final_score ?? $score,
                    'final_feedback' => trim((string) $item->final_feedback) !== '' ? $item->final_feedback : $item->ai_feedback,
                    'canvas_published_score' => $score,
                    'canvas_published_feedback' => $feedback,
                    'published_rubric_feedback' => $rubricFeedback,
                    'canvas_publication_error' => null,
                    'published_at' => now(),
                    'failure_reason' => null,
                    'failed_at' => null,
                    'review_status' => $teacherInitiated
                        ? AiGraderCorrectionItem::REVIEW_STATUS_PUBLISHED
                        : $item->review_status,
                    'status' => AiGraderCorrectionItem::STATUS_PUBLISHED_TO_CANVAS,
                ])->save();

                $this->logPublication($item, $endpoint, $payload, $response->status(), $responseBody, true, $score, $feedback);
            });

            return $this->row($item, $oldStatus, AiGraderCorrectionItem::STATUS_PUBLISHED_TO_CANVAS, $score, $endpoint, 'published', 'published', 'ok');
        } catch (Throwable $exception) {
            $message = $this->exceptionMessage($exception);
            $responseStatus = $lastResponseStatus ?? $this->exceptionStatus($exception);
            $responseBody = $lastResponseBody !== [] ? $lastResponseBody : $this->exceptionResponseBody($exception);

            DB::transaction(function () use ($item, $endpoint, $payload, $score, $feedback, $message, $responseStatus, $responseBody): void {
                $this->markFailed($item, $message);
                $this->logPublication($item, $endpoint, $payload, $responseStatus, $responseBody, false, $score, $feedback, $message);
            });

            return $this->row($item, $oldStatus, AiGraderCorrectionItem::STATUS_PUBLICATION_FAILED, $score, $endpoint, 'failed', 'failed', $message);
        }
    }

    private function eligibilityError(AiGraderCorrectionItem $item, bool $teacherInitiated = false): ?string
    {
        if ($teacherInitiated) {
            if (! in_array($item->status, AiGraderCorrectionItem::teacherPublishableStatuses(), true)) {
                return 'status_not_publishable';
            }
        } elseif ($item->status !== AiGraderCorrectionItem::STATUS_AI_CORRECTED) {
            return 'status_not_publishable';
        }

        if (! $teacherInitiated && AiGraderBlueprintConfig::normalizePublicationMode($item->publication_mode) !== AiGraderBlueprintConfig::PUBLICATION_AUTO_PUBLISH) {
            return 'publication_mode_not_publishable';
        }

        foreach (['child_course_id', 'canvas_quiz_id', 'canvas_quiz_submission_id', 'canvas_question_id'] as $field) {
            if (trim((string) $item->{$field}) === '') {
                return "missing_{$field}";
            }
        }

        if ($item->attempt === null || (int) $item->attempt < 1) {
            return 'missing_attempt';
        }

        if ($this->scoreValue($item) === null) {
            return 'missing_score';
        }

        if ($this->feedbackFor($item) === '') {
            return 'missing_feedback';
        }

        if ((float) $item->canvas_points_possible <= 0) {
            return 'invalid_points_possible';
        }

        if (! $item->canvasEnvironment) {
            return 'missing_canvas_environment';
        }

        return null;
    }

    private function scoreFor(AiGraderCorrectionItem $item): float
    {
        return $this->scoreNormalizer->normalize((float) $this->scoreValue($item), (float) $item->canvas_points_possible);
    }

    private function scoreValue(AiGraderCorrectionItem $item): mixed
    {
        return $item->final_score !== null ? $item->final_score : $item->ai_score;
    }

    private function feedbackFor(AiGraderCorrectionItem $item): string
    {
        $finalFeedback = trim((string) $item->final_feedback);

        $feedback = $finalFeedback !== '' ? $finalFeedback : trim((string) $item->ai_feedback);

        if (! $item->usesCanvasRubric()) {
            return $feedback;
        }

        return $this->rubricCommentFor($item, $feedback);
    }

    private function endpointFor(AiGraderCorrectionItem $item): string
    {
        return "/api/v1/courses/{$item->child_course_id}/quizzes/{$item->canvas_quiz_id}/submissions/{$item->canvas_quiz_submission_id}";
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(AiGraderCorrectionItem $item, float $score, string $feedback): array
    {
        return [
            'quiz_submissions' => [
                [
                    'attempt' => (int) $item->attempt,
                    'questions' => [
                        (string) $item->canvas_question_id => [
                            'score' => $score,
                            'comment' => $feedback,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function rubricFeedbackFor(AiGraderCorrectionItem $item): ?array
    {
        $feedback = $item->teacher_rubric_feedback ?? $item->ai_rubric_feedback;

        return is_array($feedback) && $feedback !== [] ? $this->sanitizer->sanitizeArray($feedback) : null;
    }

    private function rubricCommentFor(AiGraderCorrectionItem $item, string $generalFeedback): string
    {
        $locale = $this->commentLocale($item);
        $score = $this->scoreFor($item);
        $pointsPossible = (float) $item->canvas_points_possible;
        $rubricPercentage = $this->rubricPercentageFor($item, $score, $pointsPossible);
        $rubricFeedback = $this->rubricFeedbackFor($item) ?? [];
        $lines = [
            trans('ai-grader.rubric.comment.final_score', [], $locale) . ': ' . $this->number($score) . ' / ' . $this->number($pointsPossible),
        ];

        if ($rubricPercentage !== null) {
            $lines[] = trans('ai-grader.rubric.comment.rubric_result', [], $locale) . ': ' . $this->number($rubricPercentage) . '% / 100%';
        }

        if ($generalFeedback !== '') {
            $lines[] = '';
            $lines[] = trans('ai-grader.rubric.comment.general_feedback', [], $locale) . ':';
            $lines[] = $generalFeedback;
        }

        if ($rubricFeedback !== []) {
            $lines[] = '';
            $lines[] = trans('ai-grader.rubric.comment.criteria_analysis', [], $locale) . ':';

            foreach ($rubricFeedback as $criterion) {
                if (! is_array($criterion)) {
                    continue;
                }

                $label = trim((string) ($criterion['criterion_name'] ?? $criterion['criterion_id'] ?? trans('ai-grader.rubric.comment.criterion', [], $locale)));
                $percentage = $this->numericOrNull($criterion['percentage'] ?? null);
                $maxPercentage = $this->numericOrNull($criterion['max_percentage'] ?? null);
                $criterionFeedback = trim((string) ($criterion['feedback'] ?? ''));
                $line = '- ' . $label;

                if ($percentage !== null) {
                    $line .= ': ' . $this->number($percentage) . '%';
                }

                if ($maxPercentage !== null) {
                    $line .= ' (' . trans('ai-grader.rubric.comment.of_weight', ['value' => $this->number($maxPercentage)], $locale) . ')';
                }

                if ($criterionFeedback !== '') {
                    $line .= ' - ' . $criterionFeedback;
                }

                $lines[] = $line;
            }
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @return array<string, mixed>
     */
    private function responseBody(Response $response): array
    {
        $body = $response->json();

        return is_array($body) ? $body : ['raw_body' => $response->body()];
    }

    private function markFailed(AiGraderCorrectionItem $item, string $message): void
    {
        $item->forceFill([
            'status' => AiGraderCorrectionItem::STATUS_PUBLICATION_FAILED,
            'failed_at' => now(),
            'failure_reason' => $message,
            'canvas_publication_error' => $message,
        ])->save();
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $responseBody
     */
    private function logPublication(
        AiGraderCorrectionItem $item,
        string $endpoint,
        array $payload,
        ?int $responseStatus,
        array $responseBody,
        bool $success,
        float $score,
        string $feedback,
        ?string $errorMessage = null,
    ): void {
        AiGraderCanvasPublicationLog::query()->create([
            'ai_grader_correction_item_id' => $item->id,
            'organization_id' => $item->organization_id,
            'canvas_environment_id' => $item->canvas_environment_id,
            'endpoint' => $endpoint,
            'http_method' => 'PUT',
            'payload' => $this->sanitizer->sanitizeArray($payload),
            'response_status' => $responseStatus,
            'response_body' => $this->sanitizer->sanitizeArray($responseBody),
            'success' => $success,
            'published_score' => $score,
            'published_feedback' => $feedback,
            'error_message' => $errorMessage,
            'created_at' => now(),
        ]);
    }

    private function httpFailureMessage(Response $response): string
    {
        $body = $response->json();
        $message = null;

        if (is_array($body)) {
            $message = $body['message'] ?? $body['error'] ?? null;

            if (! $message && isset($body['errors']) && is_array($body['errors'])) {
                $message = collect($body['errors'])
                    ->map(fn (mixed $error): string => is_array($error) ? (string) ($error['message'] ?? json_encode($error)) : (string) $error)
                    ->filter()
                    ->implode(' | ');
            }
        }

        return mb_substr('[HTTP ' . $response->status() . '] ' . ($message ?: $response->body()), 0, 500);
    }

    private function exceptionMessage(Throwable $exception): string
    {
        return mb_substr($exception->getMessage(), 0, 500);
    }

    private function exceptionStatus(Throwable $exception): ?int
    {
        if (preg_match('/(?:\[HTTP\s*|HTTP\s*|status code\s+)(\d{3})\]?/i', $exception->getMessage(), $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function exceptionResponseBody(Throwable $exception): array
    {
        if (preg_match('/(\{.*\})/s', $exception->getMessage(), $matches) !== 1) {
            return [];
        }

        $body = json_decode($matches[1], true);

        return is_array($body) ? $body : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        AiGraderCorrectionItem $item,
        string $oldStatus,
        string $newStatus,
        float|string $score,
        string $endpoint,
        string $canvasResult,
        string $result,
        string $message,
    ): array {
        return [
            'item_id' => $item->id,
            'student' => $item->canvas_user_name ?: $item->canvas_user_id,
            'question_id' => $item->canvas_question_id,
            'score' => $score,
            'points_possible' => $item->canvas_points_possible,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'canvas_quiz_submission_id' => $item->canvas_quiz_submission_id,
            'endpoint' => $endpoint,
            'canvas_result' => $canvasResult,
            'result' => $result,
            'message' => $message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(int $itemsFound, bool $dryRun): array
    {
        return [
            'items_found' => $itemsFound,
            'published' => 0,
            'ignored' => 0,
            'failed' => 0,
            'dry_run' => $dryRun,
            'items' => [],
        ];
    }

    private function toJson(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '[unserializable]';
    }

    private function commentLocale(AiGraderCorrectionItem $item): string
    {
        return $item->blueprintConfig?->default_feedback_language === 'en' ? 'en' : 'pt_BR';
    }

    private function rubricPercentageFor(AiGraderCorrectionItem $item, float $score, float $pointsPossible): ?float
    {
        if (is_numeric($item->ai_rubric_percentage)) {
            return round((float) $item->ai_rubric_percentage, 2);
        }

        $rubricFeedback = $this->rubricFeedbackFor($item) ?? [];
        $weighted = 0.0;
        $hasWeight = false;

        foreach ($rubricFeedback as $criterion) {
            if (! is_array($criterion)) {
                continue;
            }

            $percentage = $this->numericOrNull($criterion['percentage'] ?? null);
            $maxPercentage = $this->numericOrNull($criterion['max_percentage'] ?? null);

            if ($percentage === null || $maxPercentage === null) {
                continue;
            }

            $hasWeight = true;
            $weighted += ($percentage * $maxPercentage) / 100;
        }

        if ($hasWeight) {
            return round($weighted, 2);
        }

        if ($pointsPossible <= 0) {
            return null;
        }

        return round(($score / $pointsPossible) * 100, 2);
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function number(float $value): string
    {
        $formatted = number_format($value, 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
