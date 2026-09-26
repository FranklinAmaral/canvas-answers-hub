<?php

declare(strict_types=1);

namespace App\Services\AiGrader;

use App\Models\AiGraderBatch;
use App\Models\AiGraderBlueprintConfig;
use App\Models\AiGraderClientSetting;
use App\Models\AiGraderCorrectionItem;
use App\Models\AiGraderQuestionConfig;
use App\Models\AiGraderQuizConfig;
use App\Models\CanvasEnvironment;
use App\Services\Canvas\CanvasApiClient;
use App\Services\Canvas\Quizzes\CanvasQuizzesService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

class AiGraderSubmissionCollector
{
    public function __construct(
        private readonly AiGraderQuotaService $quotaService,
        private readonly AiGraderHtmlTextExtractor $htmlTextExtractor,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function collect(array $filters = []): array
    {
        $summary = $this->emptySummary($filters);
        $dryRun = (bool) ($filters['dry_run'] ?? false);
        $limit = isset($filters['limit']) && is_numeric($filters['limit']) ? max(0, (int) $filters['limit']) : null;

        $quizConfigs = $this->eligibleQuizConfigs($filters)->get();
        $summary['configurations_found'] = $quizConfigs->count();

        foreach ($quizConfigs as $quizConfig) {
            if ($this->limitReached($summary, $limit)) {
                break;
            }

            $this->collectQuiz($quizConfig, $filters, $summary, $dryRun, $limit);
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $filters
     * @return Builder<AiGraderQuizConfig>
     */
    private function eligibleQuizConfigs(array $filters): Builder
    {
        return AiGraderQuizConfig::query()
            ->with([
                'blueprintConfig.organization.aiGraderClientSetting',
                'blueprintConfig.canvasEnvironment',
                'questionConfigs',
            ])
            ->where('enabled', true)
            ->where('correction_enabled', true)
            ->whereNotNull('canvas_assignment_id')
            ->where('canvas_assignment_id', '!=', '')
            ->when(isset($filters['quiz_config_id']), fn (Builder $query) => $query->whereKey($filters['quiz_config_id']))
            ->whereHas('blueprintConfig', function (Builder $query) use ($filters): void {
                $query->where('enabled', true)
                    ->when(isset($filters['organization_id']), fn (Builder $query) => $query->where('organization_id', $filters['organization_id']))
                    ->when(isset($filters['environment_id']), fn (Builder $query) => $query->where('canvas_environment_id', $filters['environment_id']))
                    ->when(isset($filters['blueprint_config_id']), fn (Builder $query) => $query->whereKey($filters['blueprint_config_id']))
                    ->whereHas('organization.aiGraderClientSetting', function (Builder $query): void {
                        $query->where('enabled', true)
                            ->whereIn('status', [
                                AiGraderClientSetting::STATUS_TRIAL,
                                AiGraderClientSetting::STATUS_ACTIVE,
                            ]);
                    });
            })
            ->whereHas('questionConfigs', function (Builder $query): void {
                $query->where('enabled', true)
                    ->where('canvas_question_type', AiGraderQuestionConfig::TYPE_ESSAY)
                    ->whereNotNull('ai_grading_instructions')
                    ->whereRaw('LENGTH(TRIM(ai_grading_instructions)) >= 30');
            });
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $summary
     */
    private function collectQuiz(AiGraderQuizConfig $quizConfig, array $filters, array &$summary, bool $dryRun, ?int $limit): void
    {
        $quizConfig->loadMissing(['blueprintConfig.organization', 'blueprintConfig.canvasEnvironment', 'questionConfigs']);

        $blueprintConfig = $quizConfig->blueprintConfig;
        $childCourseId = $this->stringOrNull($filters['child_course_id'] ?? null);

        if ($childCourseId === null) {
            $summary['skipped_configuration']++;
            $summary['errors'][] = "quiz_config_id={$quizConfig->id}: child-course-id is required until child course discovery exists.";

            return;
        }

        $client = new CanvasApiClient($blueprintConfig->canvasEnvironment);
        $quizzesService = new CanvasQuizzesService($client);

        try {
            $childQuiz = $this->resolveChildQuiz($quizzesService, $quizConfig, $childCourseId, $filters);
        } catch (Throwable $exception) {
            $summary['skipped_configuration']++;
            $summary['errors'][] = "quiz_config_id={$quizConfig->id}: " . $exception->getMessage();

            return;
        }

        if ($childQuiz['quiz_id'] === null || $childQuiz['assignment_id'] === null) {
            $summary['skipped_configuration']++;
            $summary['errors'][] = "quiz_config_id={$quizConfig->id}: child quiz or assignment could not be resolved.";

            return;
        }

        if (! $dryRun) {
            $this->persistChildCourseMapping(
                quizConfig: $quizConfig,
                childCourseId: $childCourseId,
                childQuizId: (string) $childQuiz['quiz_id'],
                childAssignmentId: (string) $childQuiz['assignment_id'],
                summary: $summary,
            );
        }

        try {
            $childQuestions = $quizzesService->listQuestions($childCourseId, $childQuiz['quiz_id']);
            $questionMapping = $this->buildQuestionMapping($quizConfig, $childQuestions, $summary);
        } catch (Throwable $exception) {
            $summary['skipped_configuration']++;
            $summary['errors'][] = "quiz_config_id={$quizConfig->id}: child questions could not be loaded. " . $exception->getMessage();

            return;
        }

        $batch = null;
        $batchBaseline = $this->batchCounters($summary);

        if (! $dryRun) {
            $batch = AiGraderBatch::query()->create([
                'organization_id' => $blueprintConfig->organization_id,
                'canvas_environment_id' => $blueprintConfig->canvas_environment_id,
                'ai_grader_blueprint_config_id' => $blueprintConfig->id,
                'ai_grader_quiz_config_id' => $quizConfig->id,
                'child_course_id' => $childCourseId,
                'trigger_type' => AiGraderBatch::TRIGGER_MANUAL,
                'status' => AiGraderBatch::STATUS_PROCESSING,
                'started_at' => now(),
                'metadata' => [
                    'filters' => $this->serializableFilters($filters),
                    'child_quiz_id' => $childQuiz['quiz_id'],
                    'child_assignment_id' => $childQuiz['assignment_id'],
                    'child_quiz_resolution' => $childQuiz['resolution'],
                ],
            ]);
        }

        try {
            $submissions = $quizzesService->listSubmissions($childCourseId, $childQuiz['quiz_id']);
            $summary['quizzes_processed']++;
            $summary['child_courses_processed'][$childCourseId] = true;
            $summary['submissions_found'] += $submissions->count();

            foreach ($submissions as $submission) {
                if ($this->limitReached($summary, $limit)) {
                    break;
                }

                if (! is_array($submission) || ! $this->isEligibleSubmission($submission)) {
                    continue;
                }

                $summary['submissions_eligible']++;
                $userId = $this->stringOrNull($submission['user_id'] ?? null);

                if ($userId === null) {
                    $summary['errors'][] = 'Quiz submission without user_id was skipped.';

                    continue;
                }

                $assignmentSubmission = $quizzesService->getAssignmentSubmission($childCourseId, $childQuiz['assignment_id'], $userId);
                $this->collectSubmissionAnswers(
                    quizConfig: $quizConfig,
                    childCourseId: $childCourseId,
                    childQuizId: (string) $childQuiz['quiz_id'],
                    childAssignmentId: (string) $childQuiz['assignment_id'],
                    questionMapping: $questionMapping,
                    quizSubmission: $submission,
                    assignmentSubmission: $assignmentSubmission,
                    batch: $batch,
                    summary: $summary,
                    dryRun: $dryRun,
                    limit: $limit,
                );
            }

            $this->finishBatch($batch, $summary, false, $batchBaseline);
        } catch (Throwable $exception) {
            $summary['errors'][] = "quiz_config_id={$quizConfig->id}: " . $exception->getMessage();
            $this->finishBatch($batch, $summary, true, $batchBaseline);
        }
    }

    /**
     * @param Collection<int, array<string, mixed>> $childQuestions
     * @param array<string, mixed> $summary
     * @return array<string, array{question_config: AiGraderQuestionConfig, child_question: array<string, mixed>, strategy: string}>
     */
    private function buildQuestionMapping(AiGraderQuizConfig $quizConfig, Collection $childQuestions, array &$summary): array
    {
        $enabledBlueprintQuestions = $quizConfig->questionConfigs
            ->filter(fn (AiGraderQuestionConfig $questionConfig): bool => $questionConfig->enabled
                && $questionConfig->canvas_question_type === AiGraderQuestionConfig::TYPE_ESSAY
                && trim((string) $questionConfig->ai_grading_instructions) !== '');

        $usableChildQuestions = $childQuestions
            ->filter(fn (mixed $question): bool => is_array($question))
            ->values();

        $mappedByChildQuestionId = [];
        $usedChildQuestionIds = [];

        foreach ($enabledBlueprintQuestions as $questionConfig) {
            $match = $this->matchChildQuestion($questionConfig, $usableChildQuestions, $usedChildQuestionIds);

            if ($match === null) {
                $summary['question_mapping_failed']++;
                $summary['question_mappings'][] = [
                    'blueprint_question_id' => (string) $questionConfig->canvas_question_id,
                    'blueprint_question_name' => $questionConfig->canvas_question_name ?: '-',
                    'child_question_id' => '-',
                    'child_question_name' => '-',
                    'type' => $questionConfig->canvas_question_type,
                    'strategy' => '-',
                    'status' => 'failed',
                ];

                continue;
            }

            $childQuestionId = (string) $this->canvasQuestionId($match['child_question']);
            $usedChildQuestionIds[$childQuestionId] = true;
            $summary['questions_mapped']++;
            $summary['question_mapping_strategy_counts'][$match['strategy']] = ($summary['question_mapping_strategy_counts'][$match['strategy']] ?? 0) + 1;
            $summary['question_mappings'][] = [
                'blueprint_question_id' => (string) $questionConfig->canvas_question_id,
                'blueprint_question_name' => $questionConfig->canvas_question_name ?: '-',
                'child_question_id' => $childQuestionId,
                'child_question_name' => (string) ($match['child_question']['question_name'] ?? '-'),
                'type' => $questionConfig->canvas_question_type,
                'strategy' => $match['strategy'],
                'status' => 'mapped',
            ];

            $mappedByChildQuestionId[$childQuestionId] = [
                'question_config' => $questionConfig,
                'child_question' => $match['child_question'],
                'strategy' => $match['strategy'],
            ];
        }

        return $mappedByChildQuestionId;
    }

    /**
     * @param Collection<int, array<string, mixed>> $childQuestions
     * @param array<string, bool> $usedChildQuestionIds
     * @return array{child_question: array<string, mixed>, strategy: string}|null
     */
    private function matchChildQuestion(AiGraderQuestionConfig $questionConfig, Collection $childQuestions, array $usedChildQuestionIds): ?array
    {
        $type = $questionConfig->canvas_question_type;
        $position = $this->positionForQuestionConfig($questionConfig);

        if ($position !== null) {
            $match = $childQuestions->first(function (array $childQuestion) use ($position, $type, $usedChildQuestionIds): bool {
                $childQuestionId = $this->canvasQuestionId($childQuestion);

                return $childQuestionId !== null
                    && ! isset($usedChildQuestionIds[$childQuestionId])
                    && (string) ($childQuestion['question_type'] ?? '') === $type
                    && (int) ($childQuestion['position'] ?? -1) === $position;
            });

            if (is_array($match)) {
                return ['child_question' => $match, 'strategy' => 'position_type'];
            }
        }

        $name = $this->normalizeQuestionComparable($questionConfig->canvas_question_name);

        if ($name !== '') {
            $match = $childQuestions->first(function (array $childQuestion) use ($name, $type, $usedChildQuestionIds): bool {
                $childQuestionId = $this->canvasQuestionId($childQuestion);

                return $childQuestionId !== null
                    && ! isset($usedChildQuestionIds[$childQuestionId])
                    && (string) ($childQuestion['question_type'] ?? '') === $type
                    && $this->normalizeQuestionComparable($childQuestion['question_name'] ?? null) === $name;
            });

            if (is_array($match)) {
                return ['child_question' => $match, 'strategy' => 'question_name_type'];
            }
        }

        $text = mb_substr($this->normalizeQuestionComparable($questionConfig->canvas_question_text), 0, 180);

        if ($text !== '') {
            $match = $childQuestions->first(function (array $childQuestion) use ($text, $type, $usedChildQuestionIds): bool {
                $childQuestionId = $this->canvasQuestionId($childQuestion);

                return $childQuestionId !== null
                    && ! isset($usedChildQuestionIds[$childQuestionId])
                    && (string) ($childQuestion['question_type'] ?? '') === $type
                    && mb_substr($this->normalizeQuestionComparable($childQuestion['question_text'] ?? null), 0, 180) === $text;
            });

            if (is_array($match)) {
                return ['child_question' => $match, 'strategy' => 'question_text_type'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{quiz_id: string|null, assignment_id: string|null, resolution: string}
     */
    private function resolveChildQuiz(CanvasQuizzesService $quizzesService, AiGraderQuizConfig $quizConfig, string $childCourseId, array $filters): array
    {
        $childQuizId = $this->stringOrNull($filters['child_quiz_id'] ?? null);
        $childAssignmentId = $this->stringOrNull($filters['child_assignment_id'] ?? null);

        if ($childQuizId !== null && $childAssignmentId !== null) {
            return [
                'quiz_id' => $childQuizId,
                'assignment_id' => $childAssignmentId,
                'resolution' => 'explicit_options',
            ];
        }

        $quiz = $quizzesService->findQuizByTitle($childCourseId, (string) $quizConfig->canvas_quiz_title);

        return [
            'quiz_id' => $this->stringOrNull($quiz['id'] ?? null) ?? $childQuizId,
            'assignment_id' => $childAssignmentId ?? $this->stringOrNull($quiz['assignment_id'] ?? null),
            'resolution' => 'matched_by_title',
        ];
    }

    /**
     * @param array<string, mixed> $quizSubmission
     * @param array<string, mixed> $assignmentSubmission
     * @param array<string, mixed> $summary
     */
    private function collectSubmissionAnswers(
        AiGraderQuizConfig $quizConfig,
        string $childCourseId,
        string $childQuizId,
        string $childAssignmentId,
        array $questionMapping,
        array $quizSubmission,
        array $assignmentSubmission,
        ?AiGraderBatch $batch,
        array &$summary,
        bool $dryRun,
        ?int $limit,
    ): void {
        $attempt = (int) ($quizSubmission['attempt'] ?? 0);
        $historySelection = $this->selectSubmissionHistory($assignmentSubmission['submission_history'] ?? [], $attempt);
        $submissionData = $historySelection['history']['submission_data'] ?? [];

        if (! is_array($submissionData)) {
            return;
        }

        foreach ($submissionData as $answerItem) {
            if ($this->limitReached($summary, $limit)) {
                break;
            }

            if (! is_array($answerItem)) {
                continue;
            }

            $questionId = $this->stringOrNull($answerItem['question_id'] ?? null);
            $mapping = $questionId !== null ? ($questionMapping[$questionId] ?? null) : null;
            $questionConfig = is_array($mapping) ? ($mapping['question_config'] ?? null) : null;
            $childQuestion = is_array($mapping) ? ($mapping['child_question'] ?? null) : null;

            if (! $questionConfig instanceof AiGraderQuestionConfig || ! is_array($childQuestion)) {
                continue;
            }

            $summary['essay_answers_found']++;
            $answerHtml = $this->stringOrNull($answerItem['text'] ?? null);
            $answerText = $this->htmlTextExtractor->toPlainText($answerHtml);
            $pointsPossible = $this->pointsPossible($childQuestion, $questionConfig);
            $status = $this->statusForAnswer($questionConfig, $answerText, $pointsPossible);

            $existing = $this->existingCorrectionItem(
                $quizConfig,
                $childCourseId,
                $childQuizId,
                $quizSubmission,
                $attempt,
                (string) $questionId,
            );

            if ($existing) {
                $summary['skipped_already_processed']++;

                continue;
            }

            $quotaReserved = false;

            if ($status === AiGraderCorrectionItem::STATUS_PENDING) {
                if (! $this->quotaService->canConsume($quizConfig->blueprintConfig->organization)) {
                    $status = AiGraderCorrectionItem::STATUS_PENDING_QUOTA;
                }
            }

            $payload = $this->correctionItemPayload(
                quizConfig: $quizConfig,
                questionConfig: $questionConfig,
                childCourseId: $childCourseId,
                childQuizId: $childQuizId,
                childAssignmentId: $childAssignmentId,
                childQuestion: $childQuestion,
                mappingStrategy: (string) ($mapping['strategy'] ?? '-'),
                quizSubmission: $quizSubmission,
                assignmentSubmission: $assignmentSubmission,
                attempt: $attempt,
                answerHtml: $answerHtml,
                answerText: $answerText,
                status: $status,
                batch: $batch,
                historyStrategy: $historySelection['strategy'],
            );

            if ($dryRun) {
                $summary['items'][] = $this->itemSummary(null, $payload, false);
                $this->incrementStatusSummary($summary, $status);

                continue;
            }

            $item = AiGraderCorrectionItem::query()->create($payload);

            if ($status === AiGraderCorrectionItem::STATUS_PENDING) {
                $this->quotaService->reserve($item);
                $quotaReserved = true;
            }

            $summary['items'][] = $this->itemSummary($item, $payload, $quotaReserved);
            $this->incrementStatusSummary($summary, $status);
        }
    }

    /**
     * @param mixed $history
     * @return array{history: array<string, mixed>, strategy: string}
     */
    private function selectSubmissionHistory(mixed $history, int $attempt): array
    {
        $items = collect(is_array($history) ? $history : [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->values();

        $matched = $items->first(fn (array $item): bool => (int) ($item['attempt'] ?? -1) === $attempt && isset($item['submission_data']));

        if (is_array($matched)) {
            return ['history' => $matched, 'strategy' => 'matched_attempt'];
        }

        $fallback = $items
            ->filter(fn (array $item): bool => isset($item['submission_data']) && is_array($item['submission_data']))
            ->sortByDesc(fn (array $item): string => (string) ($item['submitted_at'] ?? $item['created_at'] ?? ''))
            ->first();

        return [
            'history' => is_array($fallback) ? $fallback : [],
            'strategy' => 'latest_with_submission_data',
        ];
    }

    private function statusForAnswer(AiGraderQuestionConfig $questionConfig, ?string $answerText, mixed $pointsPossible): string
    {
        if ($answerText === null || trim($answerText) === '') {
            return AiGraderCorrectionItem::STATUS_SKIPPED_BLANK_ANSWER;
        }

        if (trim((string) $questionConfig->ai_grading_instructions) === '' || mb_strlen(trim((string) $questionConfig->ai_grading_instructions)) < 30) {
            return AiGraderCorrectionItem::STATUS_PENDING_CONFIGURATION;
        }

        if ($questionConfig->usesCanvasRubric() && ! $questionConfig->hasRubricSnapshot()) {
            return AiGraderCorrectionItem::STATUS_PENDING_CONFIGURATION;
        }

        if (! is_numeric($pointsPossible)) {
            return AiGraderCorrectionItem::STATUS_PENDING_CONFIGURATION;
        }

        return AiGraderCorrectionItem::STATUS_PENDING;
    }

    /**
     * @param array<string, mixed> $quizSubmission
     */
    private function existingCorrectionItem(
        AiGraderQuizConfig $quizConfig,
        string $childCourseId,
        string $childQuizId,
        array $quizSubmission,
        int $attempt,
        string $questionId,
    ): ?AiGraderCorrectionItem {
        return AiGraderCorrectionItem::query()
            ->where('organization_id', $quizConfig->blueprintConfig->organization_id)
            ->where('canvas_environment_id', $quizConfig->blueprintConfig->canvas_environment_id)
            ->where('child_course_id', $childCourseId)
            ->where('canvas_quiz_id', $childQuizId)
            ->where('canvas_quiz_submission_id', (string) ($quizSubmission['id'] ?? ''))
            ->where('attempt', $attempt)
            ->where('canvas_question_id', $questionId)
            ->where('canvas_user_id', (string) ($quizSubmission['user_id'] ?? ''))
            ->first();
    }

    /**
     * @param array<string, mixed> $quizSubmission
     * @param array<string, mixed> $assignmentSubmission
     * @return array<string, mixed>
     */
    private function correctionItemPayload(
        AiGraderQuizConfig $quizConfig,
        AiGraderQuestionConfig $questionConfig,
        string $childCourseId,
        string $childQuizId,
        string $childAssignmentId,
        array $childQuestion,
        string $mappingStrategy,
        array $quizSubmission,
        array $assignmentSubmission,
        int $attempt,
        ?string $answerHtml,
        ?string $answerText,
        string $status,
        ?AiGraderBatch $batch,
        string $historyStrategy,
    ): array {
        $blueprintConfig = $quizConfig->blueprintConfig;
        $user = is_array($assignmentSubmission['user'] ?? null) ? $assignmentSubmission['user'] : [];

        return [
            'organization_id' => $blueprintConfig->organization_id,
            'canvas_environment_id' => $blueprintConfig->canvas_environment_id,
            'ai_grader_batch_id' => $batch?->id,
            'ai_grader_blueprint_config_id' => $blueprintConfig->id,
            'ai_grader_quiz_config_id' => $quizConfig->id,
            'ai_grader_question_config_id' => $questionConfig->id,
            'blueprint_course_id' => $blueprintConfig->blueprint_course_id,
            'child_course_id' => $childCourseId,
            'canvas_quiz_id' => $childQuizId,
            'canvas_assignment_id' => $childAssignmentId,
            'canvas_quiz_submission_id' => (string) ($quizSubmission['id'] ?? ''),
            'canvas_assignment_submission_id' => $this->stringOrNull($assignmentSubmission['id'] ?? null),
            'canvas_user_id' => (string) ($quizSubmission['user_id'] ?? $assignmentSubmission['user_id'] ?? ''),
            'canvas_user_name' => $this->stringOrNull($user['name'] ?? null),
            'canvas_user_login_id' => $this->stringOrNull($user['login_id'] ?? null),
            'attempt' => $attempt,
            'canvas_question_id' => (string) ($this->canvasQuestionId($childQuestion) ?? $questionConfig->canvas_question_id),
            'canvas_question_name' => $this->stringOrNull($childQuestion['question_name'] ?? null) ?? $questionConfig->canvas_question_name,
            'canvas_question_text' => $this->stringOrNull($childQuestion['question_text'] ?? null) ?? $questionConfig->canvas_question_text,
            'canvas_points_possible' => $this->pointsPossible($childQuestion, $questionConfig),
            'answer_html' => $answerHtml,
            'answer_text' => $answerText,
            'ai_grading_instructions_snapshot' => $questionConfig->ai_grading_instructions,
            'instructions_version' => $questionConfig->instructions_version,
            'correction_mode' => $questionConfig->normalizedCorrectionMode(),
            'canvas_rubric_id' => $questionConfig->canvas_rubric_id,
            'rubric_title' => $questionConfig->rubric_title,
            'rubric_snapshot' => $questionConfig->rubric_snapshot,
            'status' => $status,
            'publication_mode' => $blueprintConfig->publication_mode,
            'metadata' => [
                'quiz_submission_workflow_state' => $quizSubmission['workflow_state'] ?? null,
                'quiz_submission_finished_at' => $quizSubmission['finished_at'] ?? null,
                'quiz_submission_score' => $quizSubmission['score'] ?? null,
                'quiz_submission_kept_score' => $quizSubmission['kept_score'] ?? null,
                'history_match_strategy' => $historyStrategy,
                'blueprint_question_id' => (string) $questionConfig->canvas_question_id,
                'child_question_id' => (string) ($this->canvasQuestionId($childQuestion) ?? ''),
                'question_mapping_strategy' => $mappingStrategy,
                'blueprint_question_name' => $questionConfig->canvas_question_name,
                'child_question_name' => $this->stringOrNull($childQuestion['question_name'] ?? null),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $submission
     */
    private function isEligibleSubmission(array $submission): bool
    {
        if (($submission['workflow_state'] ?? null) === 'untaken') {
            return false;
        }

        return $this->stringOrNull($submission['finished_at'] ?? null) !== null;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function incrementStatusSummary(array &$summary, string $status): void
    {
        match ($status) {
            AiGraderCorrectionItem::STATUS_PENDING => $summary['pending_created']++,
            AiGraderCorrectionItem::STATUS_PENDING_QUOTA => $summary['pending_quota_created']++,
            AiGraderCorrectionItem::STATUS_SKIPPED_BLANK_ANSWER => $summary['skipped_blank_answer']++,
            AiGraderCorrectionItem::STATUS_PENDING_CONFIGURATION => $summary['pending_configuration_created']++,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function itemSummary(?AiGraderCorrectionItem $item, array $payload, bool $quotaReserved): array
    {
        return [
            'correction_item_id' => $item?->id ?? 'dry-run',
            'status' => $payload['status'],
            'user_id' => $payload['canvas_user_id'],
            'user_name' => $payload['canvas_user_name'] ?? '-',
            'quiz_submission_id' => $payload['canvas_quiz_submission_id'],
            'attempt' => $payload['attempt'],
            'question_id' => $payload['canvas_question_id'],
            'answer_length' => mb_strlen((string) ($payload['answer_text'] ?? '')),
            'quota_reserved' => $quotaReserved ? 'yes' : 'no',
        ];
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function limitReached(array $summary, ?int $limit): bool
    {
        if ($limit === null) {
            return false;
        }

        $created = $summary['pending_created']
            + $summary['pending_quota_created']
            + $summary['skipped_blank_answer']
            + $summary['pending_configuration_created'];

        return $created >= $limit;
    }

    /**
     * @param array<string, mixed> $summary
     */
    /**
     * @param array<string, int> $baseline
     */
    private function finishBatch(?AiGraderBatch $batch, array $summary, bool $hasError, array $baseline): void
    {
        if (! $batch) {
            return;
        }

        $current = $this->batchCounters($summary);
        $pending = $current['pending'] - $baseline['pending'];
        $pendingQuota = $current['pending_quota'] - $baseline['pending_quota'];
        $blank = $current['blank'] - $baseline['blank'];
        $alreadyProcessed = $current['already_processed'] - $baseline['already_processed'];
        $pendingConfiguration = $current['pending_configuration'] - $baseline['pending_configuration'];
        $errors = $current['errors'] - $baseline['errors'];

        $batch->update([
            'status' => $hasError || $errors > 0
                ? AiGraderBatch::STATUS_COMPLETED_WITH_ERRORS
                : AiGraderBatch::STATUS_COMPLETED,
            'total_items' => $pending + $pendingQuota + $blank + $pendingConfiguration,
            'pending_items' => $pending,
            'skipped_items' => $blank + $alreadyProcessed,
            'quota_blocked_items' => $pendingQuota,
            'failed_items' => $errors,
            'finished_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, int>
     */
    private function batchCounters(array $summary): array
    {
        return [
            'pending' => $summary['pending_created'],
            'pending_quota' => $summary['pending_quota_created'],
            'blank' => $summary['skipped_blank_answer'],
            'already_processed' => $summary['skipped_already_processed'],
            'pending_configuration' => $summary['pending_configuration_created'],
            'errors' => count($summary['errors']),
        ];
    }

    private function positionForQuestionConfig(AiGraderQuestionConfig $questionConfig): ?int
    {
        $settings = is_array($questionConfig->settings) ? $questionConfig->settings : [];
        $position = $settings['position'] ?? null;

        if (! is_numeric($position)) {
            return null;
        }

        return (int) $position;
    }

    /**
     * @param array<string, mixed> $question
     */
    private function canvasQuestionId(array $question): ?string
    {
        return $this->stringOrNull($question['id'] ?? $question['question_id'] ?? $question['quiz_question_id'] ?? null);
    }

    private function normalizeQuestionComparable(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return mb_strtolower(trim($text));
    }

    /**
     * @param array<string, mixed> $childQuestion
     */
    private function pointsPossible(array $childQuestion, AiGraderQuestionConfig $questionConfig): mixed
    {
        if (is_numeric($childQuestion['points_possible'] ?? null)) {
            return (float) $childQuestion['points_possible'];
        }

        return $questionConfig->canvas_points_possible;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function persistChildCourseMapping(
        AiGraderQuizConfig $quizConfig,
        string $childCourseId,
        string $childQuizId,
        string $childAssignmentId,
        array &$summary,
    ): void {
        $settings = is_array($quizConfig->settings) ? $quizConfig->settings : [];
        $childCourses = collect(is_array($settings['child_courses'] ?? null) ? $settings['child_courses'] : [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->values();

        $payload = [
            'child_course_id' => $childCourseId,
            'child_quiz_id' => $childQuizId,
            'child_assignment_id' => $childAssignmentId,
            'child_course_name' => null,
            'last_seen_at' => now()->format('Y-m-d H:i:s'),
            'source' => 'collector',
            'mapping_strategy' => 'command_parameters',
        ];

        $existingIndex = $childCourses->search(function (array $item) use ($childCourseId, $childQuizId, $childAssignmentId): bool {
            return (string) ($item['child_course_id'] ?? '') === $childCourseId
                && (string) ($item['child_quiz_id'] ?? '') === $childQuizId
                && (string) ($item['child_assignment_id'] ?? '') === $childAssignmentId;
        });

        if ($existingIndex === false) {
            $childCourses->push($payload);
            $summary['child_course_mappings_persisted']++;
        } else {
            $existing = $childCourses->get($existingIndex);
            $childCourses->put($existingIndex, array_merge(is_array($existing) ? $existing : [], $payload));
            $summary['child_course_mappings_updated']++;
        }

        $settings['child_courses'] = $childCourses->values()->all();
        $quizConfig->forceFill(['settings' => $settings])->save();
        $quizConfig->refresh();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function emptySummary(array $filters): array
    {
        return [
            'filters' => $this->serializableFilters($filters),
            'configurations_found' => 0,
            'skipped_configuration' => 0,
            'quizzes_processed' => 0,
            'child_courses_processed' => [],
            'submissions_found' => 0,
            'submissions_eligible' => 0,
            'essay_answers_found' => 0,
            'pending_created' => 0,
            'pending_quota_created' => 0,
            'skipped_blank_answer' => 0,
            'skipped_already_processed' => 0,
            'pending_configuration_created' => 0,
            'questions_mapped' => 0,
            'question_mapping_failed' => 0,
            'question_mapping_strategy_counts' => [],
            'question_mappings' => [],
            'child_course_mappings_persisted' => 0,
            'child_course_mappings_updated' => 0,
            'errors' => [],
            'items' => [],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function serializableFilters(array $filters): array
    {
        return collect($filters)
            ->reject(fn (mixed $value): bool => $value === null || $value === '')
            ->all();
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
