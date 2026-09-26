<?php

declare(strict_types=1);

namespace App\Services\AiGrader;

use App\Models\AiGraderBlueprintConfig;
use App\Models\AiGraderQuizConfig;
use App\Services\Canvas\CanvasApiClient;
use App\Services\Canvas\Quizzes\CanvasQuizzesService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiGraderChildCourseResolver
{
    /**
     * @var array<string, Collection<int, array<string, mixed>>>
     */
    private array $childQuizCache = [];

    /**
     * @var array<int, array<string, mixed>|null>
     */
    private array $associatedCourseCache = [];

    /**
     * @return array{groups: Collection<int, array<string, mixed>>, auto_mapped: int, messages: array<int, string>}
     */
    public function resolvePipelineGroups(string $childCourseId, ?int $canvasEnvironmentId = null): array
    {
        $groups = collect();
        $messages = [];
        $autoMapped = 0;

        $blueprints = AiGraderBlueprintConfig::query()
            ->with(['canvasEnvironment', 'quizConfigs'])
            ->where('enabled', true)
            ->when($canvasEnvironmentId !== null, fn ($query) => $query->where('canvas_environment_id', $canvasEnvironmentId))
            ->orderBy('id')
            ->get();

        foreach ($blueprints as $blueprintConfig) {
            foreach ($blueprintConfig->quizConfigs as $quizConfig) {
                if (! $quizConfig instanceof AiGraderQuizConfig || ! $this->eligibleQuizConfig($quizConfig)) {
                    continue;
                }

                $existingMapping = $this->existingMappingForCourse($quizConfig, $childCourseId);

                if ($existingMapping !== null) {
                    $groups->push($this->groupPayload($blueprintConfig, $childCourseId, $existingMapping));

                    continue;
                }

                try {
                    $associatedCourse = $this->associatedCourseForBlueprint($blueprintConfig, $childCourseId);
                } catch (Throwable $exception) {
                    $messages[] = $exception->getMessage();

                    continue;
                }

                if ($associatedCourse === null) {
                    continue;
                }

                try {
                    $mapped = $this->mapQuizForChildCourse($blueprintConfig, $quizConfig, $childCourseId, $associatedCourse);
                } catch (Throwable $exception) {
                    $messages[] = $exception->getMessage();

                    continue;
                }

                if ($mapped === null) {
                    $messages[] = __('ai_grader.lti.force_refresh_mapping_failed', [
                        'quiz' => (string) ($quizConfig->canvas_quiz_title ?: $quizConfig->canvas_quiz_id),
                    ]);

                    continue;
                }

                $groups->push($this->groupPayload($blueprintConfig, $childCourseId, $mapped));
                $autoMapped++;
            }
        }

        return [
            'groups' => $groups
                ->filter(fn (array $group): bool => $group['child_course_id'] !== ''
                    && $group['canvas_quiz_id'] !== ''
                    && $group['canvas_assignment_id'] !== '')
                ->unique(fn (array $group): string => implode('|', [
                    $group['blueprint_config_id'],
                    $group['child_course_id'],
                    $group['canvas_quiz_id'],
                    $group['canvas_assignment_id'],
                ]))
                ->values(),
            'auto_mapped' => $autoMapped,
            'messages' => array_values(array_unique(array_filter($messages))),
        ];
    }

    private function eligibleQuizConfig(AiGraderQuizConfig $quizConfig): bool
    {
        return $quizConfig->enabled
            && $quizConfig->correction_enabled
            && trim((string) $quizConfig->canvas_quiz_title) !== '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function existingMappingForCourse(AiGraderQuizConfig $quizConfig, string $childCourseId): ?array
    {
        return collect(data_get($quizConfig->settings, 'child_courses', []))
            ->first(function (mixed $mapping) use ($childCourseId): bool {
                return is_array($mapping)
                    && (string) ($mapping['child_course_id'] ?? '') === $childCourseId
                    && trim((string) ($mapping['canvas_quiz_id'] ?? $mapping['child_quiz_id'] ?? '')) !== ''
                    && trim((string) ($mapping['canvas_assignment_id'] ?? $mapping['child_assignment_id'] ?? '')) !== '';
            });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function associatedCourseForBlueprint(AiGraderBlueprintConfig $blueprintConfig, string $childCourseId): ?array
    {
        if (array_key_exists($blueprintConfig->id, $this->associatedCourseCache)) {
            return $this->associatedCourseCache[$blueprintConfig->id];
        }

        $blueprintConfig->loadMissing('canvasEnvironment');

        if (! $blueprintConfig->canvasEnvironment) {
            throw new \RuntimeException(sprintf(
                'A blueprint %s nao possui Canvas Environment ativo para mapear a disciplina filha %s.',
                $blueprintConfig->id,
                $childCourseId
            ));
        }

        $client = new CanvasApiClient($blueprintConfig->canvasEnvironment);

        $courses = $client->getPaginated(
            "/api/v1/courses/{$blueprintConfig->blueprint_course_id}/blueprint_templates/default/associated_courses?per_page=100"
        );

        $course = $courses->first(function (mixed $item) use ($childCourseId): bool {
            return is_array($item)
                && (string) ($item['id'] ?? $item['course_id'] ?? '') === $childCourseId;
        });

        $resolved = is_array($course) ? $course : null;
        $this->associatedCourseCache[$blueprintConfig->id] = $resolved;

        return $resolved;
    }

    /**
     * @param array<string, mixed> $associatedCourse
     * @return array<string, mixed>|null
     */
    private function mapQuizForChildCourse(
        AiGraderBlueprintConfig $blueprintConfig,
        AiGraderQuizConfig $quizConfig,
        string $childCourseId,
        array $associatedCourse,
    ): ?array {
        $quiz = $this->findChildQuizByTitle($blueprintConfig, $childCourseId, (string) $quizConfig->canvas_quiz_title);

        if (! is_array($quiz)) {
            return null;
        }

        $canvasQuizId = $this->stringOrNull($quiz['id'] ?? null);
        $canvasAssignmentId = $this->stringOrNull($quiz['assignment_id'] ?? null);

        if ($canvasQuizId === null || $canvasAssignmentId === null) {
            return null;
        }

        $mapping = [
            'child_course_id' => $childCourseId,
            'child_course_name' => $this->stringOrNull($associatedCourse['name'] ?? $associatedCourse['course_name'] ?? null),
            'canvas_quiz_id' => $canvasQuizId,
            'canvas_assignment_id' => $canvasAssignmentId,
            'child_quiz_id' => $canvasQuizId,
            'child_assignment_id' => $canvasAssignmentId,
            'mapping_strategy' => 'quiz_title',
            'last_seen_at' => now()->format('Y-m-d H:i:s'),
            'source' => 'lti_force_refresh',
        ];

        $this->persistMapping($quizConfig, $mapping);

        Log::info('AI Grader child course mapping created automatically.', [
            'quiz_config_id' => $quizConfig->id,
            'blueprint_config_id' => $blueprintConfig->id,
            'child_course_id' => $childCourseId,
            'canvas_quiz_id' => $canvasQuizId,
            'canvas_assignment_id' => $canvasAssignmentId,
            'mapping_strategy' => 'quiz_title',
        ]);

        return $mapping;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findChildQuizByTitle(AiGraderBlueprintConfig $blueprintConfig, string $childCourseId, string $quizTitle): ?array
    {
        $cacheKey = $blueprintConfig->canvas_environment_id . ':' . $childCourseId;

        if (! array_key_exists($cacheKey, $this->childQuizCache)) {
            if (! $blueprintConfig->canvasEnvironment) {
                throw new \RuntimeException(sprintf(
                    'A blueprint %s nao possui Canvas Environment ativo para consultar quizzes da disciplina filha %s.',
                    $blueprintConfig->id,
                    $childCourseId
                ));
            }

            $service = new CanvasQuizzesService(new CanvasApiClient($blueprintConfig->canvasEnvironment));
            $this->childQuizCache[$cacheKey] = $service->listQuizzes($childCourseId);
        }

        $matches = $this->childQuizCache[$cacheKey]
            ->filter(fn (mixed $quiz): bool => is_array($quiz)
                && $this->normalizeTitle($quiz['title'] ?? null) === $this->normalizeTitle($quizTitle))
            ->values();

        if ($matches->count() > 1) {
            throw new \RuntimeException(sprintf(
                'Mais de um quiz com o titulo "%s" foi encontrado na disciplina filha %s.',
                $quizTitle,
                $childCourseId
            ));
        }

        $match = $matches->first();

        return is_array($match) ? $match : null;
    }

    /**
     * @param array<string, mixed> $mapping
     */
    private function persistMapping(AiGraderQuizConfig $quizConfig, array $mapping): void
    {
        $settings = is_array($quizConfig->settings) ? $quizConfig->settings : [];
        $childCourses = collect(is_array($settings['child_courses'] ?? null) ? $settings['child_courses'] : [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->values();

        $index = $childCourses->search(fn (array $item): bool => (string) ($item['child_course_id'] ?? '') === (string) $mapping['child_course_id']);

        if ($index === false) {
            $childCourses->push($mapping);
        } else {
            $existing = $childCourses->get($index);
            $childCourses->put($index, array_merge(is_array($existing) ? $existing : [], $mapping));
        }

        $settings['child_courses'] = $childCourses->values()->all();
        $quizConfig->forceFill(['settings' => $settings])->save();
    }

    /**
     * @param array<string, mixed> $mapping
     * @return array<string, mixed>
     */
    private function groupPayload(AiGraderBlueprintConfig $blueprintConfig, string $childCourseId, array $mapping): array
    {
        return [
            'blueprint_config_id' => (int) $blueprintConfig->id,
            'child_course_id' => $childCourseId,
            'canvas_quiz_id' => (string) ($mapping['canvas_quiz_id'] ?? $mapping['child_quiz_id'] ?? ''),
            'canvas_assignment_id' => (string) ($mapping['canvas_assignment_id'] ?? $mapping['child_assignment_id'] ?? ''),
        ];
    }

    private function normalizeTitle(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $value) ?? ''));
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
