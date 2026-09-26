<?php

declare(strict_types=1);

namespace App\Services\AiGrader;

use App\Models\AiGraderBlueprintConfig;
use App\Models\AiGraderQuestionConfig;
use App\Models\AiGraderQuizConfig;
use App\Services\Canvas\CanvasApiClient;
use App\Services\Canvas\Quizzes\CanvasQuizzesService;
use Illuminate\Support\Collection;

class AiGraderCanvasSyncService
{
    /**
     * @return array{created: int, updated: int, ignored: int}
     */
    public function syncQuizzes(AiGraderBlueprintConfig $blueprintConfig): array
    {
        $blueprintConfig->loadMissing('canvasEnvironment');

        $service = new CanvasQuizzesService(new CanvasApiClient($blueprintConfig->canvasEnvironment));
        $quizzes = $service->listQuizzes($blueprintConfig->blueprint_course_id);
        $result = ['created' => 0, 'updated' => 0, 'ignored' => 0];

        foreach ($quizzes as $quiz) {
            if (! is_array($quiz) || ! $this->isClassicQuiz($quiz)) {
                $result['ignored']++;

                continue;
            }

            $quizConfig = AiGraderQuizConfig::query()->firstOrNew([
                'ai_grader_blueprint_config_id' => $blueprintConfig->id,
                'canvas_quiz_id' => (string) ($quiz['id'] ?? ''),
            ]);

            $wasRecentlyCreated = ! $quizConfig->exists;

            $quizConfig->fill([
                'canvas_assignment_id' => (string) ($quiz['assignment_id'] ?? ''),
                'canvas_quiz_title' => $quiz['title'] ?? null,
                'canvas_quiz_type' => $quiz['quiz_type'] ?? null,
                'points_possible' => is_numeric($quiz['points_possible'] ?? null) ? (float) $quiz['points_possible'] : null,
                'question_count' => is_numeric($quiz['question_count'] ?? null) ? (int) $quiz['question_count'] : null,
                'last_synced_at' => now(),
            ]);

            if ($wasRecentlyCreated) {
                $quizConfig->enabled = false;
                $quizConfig->correction_enabled = false;
            }

            $quizConfig->save();
            $result[$wasRecentlyCreated ? 'created' : 'updated']++;
        }

        return $result;
    }

    /**
     * @return array{synced: int, essay: int, objective_ignored: int}
     */
    public function syncQuestions(AiGraderQuizConfig $quizConfig): array
    {
        $quizConfig->loadMissing('blueprintConfig.canvasEnvironment');

        $blueprintConfig = $quizConfig->blueprintConfig;
        $service = new CanvasQuizzesService(new CanvasApiClient($blueprintConfig->canvasEnvironment));
        $questions = $service->listQuestions($blueprintConfig->blueprint_course_id, $quizConfig->canvas_quiz_id);
        $result = ['synced' => 0, 'essay' => 0, 'objective_ignored' => 0];

        AiGraderQuestionConfig::query()
            ->where('ai_grader_quiz_config_id', $quizConfig->id)
            ->where('canvas_question_type', '!=', AiGraderQuestionConfig::TYPE_ESSAY)
            ->delete();

        foreach ($questions as $question) {
            if (! is_array($question)) {
                continue;
            }

            $questionType = (string) ($question['question_type'] ?? '');

            if ($questionType !== AiGraderQuestionConfig::TYPE_ESSAY) {
                $result['objective_ignored']++;

                continue;
            }

            $result['essay']++;

            $questionConfig = AiGraderQuestionConfig::query()->firstOrNew([
                'ai_grader_quiz_config_id' => $quizConfig->id,
                'canvas_question_id' => (string) ($question['id'] ?? $question['question_id'] ?? ''),
            ]);

            $wasRecentlyCreated = ! $questionConfig->exists;

            $questionConfig->fill([
                'canvas_question_name' => $question['question_name'] ?? null,
                'canvas_question_type' => $questionType,
                'canvas_question_text' => $question['question_text'] ?? null,
                'canvas_points_possible' => is_numeric($question['points_possible'] ?? null) ? (float) $question['points_possible'] : null,
                'canvas_neutral_comments_snapshot' => $this->neutralCommentsSnapshot($question),
                'last_synced_at' => now(),
                'settings' => array_filter(array_merge($questionConfig->settings ?? [], [
                    'position' => is_numeric($question['position'] ?? null) ? (int) $question['position'] : null,
                ]), fn (mixed $value): bool => $value !== null),
            ]);

            if ($wasRecentlyCreated) {
                $questionConfig->enabled = false;
            }

            $questionConfig->save();
            $result['synced']++;
        }

        return $result;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listCourseRubrics(AiGraderBlueprintConfig $blueprintConfig): Collection
    {
        $blueprintConfig->loadMissing('canvasEnvironment');

        $service = new CanvasQuizzesService(new CanvasApiClient($blueprintConfig->canvasEnvironment));

        return $service
            ->listRubrics($blueprintConfig->blueprint_course_id)
            ->filter(fn (mixed $rubric): bool => is_array($rubric))
            ->map(fn (array $rubric): array => $this->normalizeRubric($rubric))
            ->filter(fn (array $rubric): bool => $rubric['id'] !== '')
            ->values();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function rubricSnapshotForCourse(AiGraderBlueprintConfig $blueprintConfig, string $rubricId): ?array
    {
        return $this->listCourseRubrics($blueprintConfig)
            ->first(fn (array $rubric): bool => (string) $rubric['id'] === (string) $rubricId);
    }

    /**
     * @param array<string, mixed> $quiz
     */
    private function isClassicQuiz(array $quiz): bool
    {
        $quizType = (string) ($quiz['quiz_type'] ?? '');

        if ($quizType === 'new_quizzes') {
            return false;
        }

        return isset($quiz['id']) && ($quizType !== '' || array_key_exists('assignment_id', $quiz));
    }

    /**
     * @param array<string, mixed> $question
     */
    private function neutralCommentsSnapshot(array $question): ?string
    {
        foreach (['neutral_comments_html', 'neutral_comments', 'general_comments_html', 'general_comments'] as $field) {
            if (isset($question[$field]) && is_scalar($question[$field]) && trim((string) $question[$field]) !== '') {
                return (string) $question[$field];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $rubric
     * @return array<string, mixed>
     */
    private function normalizeRubric(array $rubric): array
    {
        $title = trim((string) ($rubric['title'] ?? $rubric['description'] ?? ''));
        $criteria = $this->normalizeRubricCriteria($rubric['data'] ?? $rubric['criteria'] ?? []);
        $pointsPossible = $this->numericOrNull($rubric['points_possible'] ?? null)
            ?? collect($criteria)
                ->sum(fn (array $criterion): float => $this->numericOrNull($criterion['max_points'] ?? null) ?? 0.0);

        return [
            'id' => trim((string) ($rubric['id'] ?? '')),
            'title' => $title !== '' ? $title : trim((string) ($rubric['id'] ?? '')),
            'points_possible' => $pointsPossible,
            'criterion_count' => count($criteria),
            'criteria' => $this->applyCriterionPercentages($criteria, $pointsPossible),
        ];
    }

    /**
     * @param mixed $criteria
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRubricCriteria(mixed $criteria): array
    {
        $items = [];

        foreach ($this->iterableValues($criteria) as $criterion) {
            if (! is_array($criterion)) {
                continue;
            }

            $maxPoints = $this->numericOrNull($criterion['points'] ?? $criterion['max_points'] ?? null);
            $name = trim((string) ($criterion['description'] ?? $criterion['name'] ?? $criterion['id'] ?? ''));

            $items[] = [
                'id' => trim((string) ($criterion['id'] ?? '')),
                'name' => $name !== '' ? $name : trim((string) ($criterion['id'] ?? '')),
                'description' => $name !== '' ? $name : trim((string) ($criterion['id'] ?? '')),
                'long_description' => $this->stringOrNull($criterion['long_description'] ?? $criterion['criterion_description'] ?? null),
                'max_points' => $maxPoints,
                'ratings' => $this->normalizeRubricRatings($criterion['ratings'] ?? [], $maxPoints),
            ];
        }

        return $items;
    }

    /**
     * @param mixed $ratings
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRubricRatings(mixed $ratings, ?float $criterionMaxPoints): array
    {
        $items = [];

        foreach ($this->iterableValues($ratings) as $rating) {
            if (! is_array($rating)) {
                continue;
            }

            $points = $this->numericOrNull($rating['points'] ?? null);
            $items[] = [
                'id' => trim((string) ($rating['id'] ?? '')),
                'description' => trim((string) ($rating['description'] ?? $rating['name'] ?? $rating['id'] ?? '')),
                'long_description' => $this->stringOrNull($rating['long_description'] ?? null),
                'points' => $points,
                'percentage' => $criterionMaxPoints !== null && $criterionMaxPoints > 0 && $points !== null
                    ? round(($points / $criterionMaxPoints) * 100, 2)
                    : null,
            ];
        }

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $criteria
     * @return array<int, array<string, mixed>>
     */
    private function applyCriterionPercentages(array $criteria, ?float $pointsPossible): array
    {
        $total = $pointsPossible ?? 0.0;

        return array_map(function (array $criterion) use ($total): array {
            $maxPoints = $this->numericOrNull($criterion['max_points'] ?? null);
            $criterion['max_percentage'] = $total > 0 && $maxPoints !== null
                ? round(($maxPoints / $total) * 100, 2)
                : null;

            return $criterion;
        }, $criteria);
    }

    /**
     * @return array<int, mixed>
     */
    private function iterableValues(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values($value);
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
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
