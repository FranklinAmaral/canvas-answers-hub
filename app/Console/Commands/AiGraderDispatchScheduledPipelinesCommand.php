<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\AiGrader\AiGraderRunPipelineJob;
use App\Models\AiGraderBlueprintConfig;
use App\Models\AiGraderClientSetting;
use App\Models\AiGraderQuestionConfig;
use App\Models\AiGraderQuizConfig;
use App\Services\AiGrader\AiGraderProviderResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AiGraderDispatchScheduledPipelinesCommand extends Command
{
    protected $signature = 'ai-grader:dispatch-scheduled-pipelines
        {--organization-id= : Filter by organization ID}
        {--environment-id= : Filter by Canvas environment ID}
        {--blueprint-config-id= : Dispatch only one GraderAI blueprint config ID}
        {--limit= : Limit blueprints evaluated in this run}
        {--sync : Execute pipeline jobs immediately instead of dispatching to queue}';

    protected $description = 'Dispatch scheduled GraderAI pipelines.';

    public function handle(AiGraderProviderResolver $providerResolver): int
    {
        $summary = $this->emptySummary();
        $rows = [];
        $now = now();

        Log::info('GraderAI scheduled pipeline dispatcher started.', [
            'filters' => $this->filters(),
            'sync' => (bool) $this->option('sync'),
        ]);

        foreach ($this->blueprints() as $blueprintConfig) {
            $summary['blueprints_evaluated']++;

            try {
                $this->evaluateBlueprint($blueprintConfig, $now, $summary, $rows, $providerResolver);
            } catch (Throwable $exception) {
                $summary['errors']++;
                $rows[] = $this->row($blueprintConfig, null, 'error', $exception->getMessage());

                Log::error('GraderAI scheduled pipeline dispatcher failed for blueprint.', [
                    'blueprint_config_id' => $blueprintConfig->id,
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ]);
            }
        }

        $this->renderSummary($summary);
        $this->renderRows($rows);

        Log::info('GraderAI scheduled pipeline dispatcher finished.', [
            'summary' => $summary,
        ]);

        return $summary['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param array<string, int> $summary
     * @param array<int, array<string, mixed>> $rows
     */
    private function evaluateBlueprint(
        AiGraderBlueprintConfig $blueprintConfig,
        Carbon $now,
        array &$summary,
        array &$rows,
        AiGraderProviderResolver $providerResolver,
    ): void
    {
        if (! $this->isEligible($blueprintConfig, $providerResolver)) {
            $summary['blueprints_skipped']++;
            $rows[] = $this->row($blueprintConfig, null, 'skipped', 'not_eligible');

            return;
        }

        $triggerMode = (string) $blueprintConfig->trigger_mode;

        if ($triggerMode === AiGraderBlueprintConfig::TRIGGER_MANUAL) {
            $summary['blueprints_skipped']++;
            $summary['skipped_manual']++;
            $rows[] = $this->row($blueprintConfig, null, 'skipped', 'manual');

            return;
        }

        if ($triggerMode === AiGraderBlueprintConfig::TRIGGER_AFTER_DATE) {
            if (! $blueprintConfig->scheduled_at) {
                $summary['blueprints_skipped']++;
                $summary['skipped_missing_scheduled_at']++;
                $rows[] = $this->row($blueprintConfig, null, 'skipped', 'skipped_missing_scheduled_at');

                return;
            }

            if ($blueprintConfig->scheduled_at->isFuture()) {
                $summary['blueprints_skipped']++;
                $rows[] = $this->row($blueprintConfig, null, 'skipped', 'scheduled_at_not_reached');

                return;
            }

            if ($this->afterDateAlreadyDispatched($blueprintConfig)) {
                $summary['blueprints_skipped']++;
                $summary['skipped_after_date_already_dispatched']++;
                $rows[] = $this->row($blueprintConfig, null, 'skipped', 'after_date_already_dispatched');

                return;
            }
        }

        $childCourses = $this->childCourses($blueprintConfig);

        if ($childCourses === []) {
            $summary['blueprints_skipped']++;
            $summary['skipped_missing_child_course_mapping']++;
            $rows[] = $this->row($blueprintConfig, null, 'skipped', 'skipped_missing_child_course_mapping');

            return;
        }

        $summary['blueprints_eligible']++;

        foreach ($childCourses as $childCourse) {
            $job = new AiGraderRunPipelineJob(
                blueprintConfigId: (int) $blueprintConfig->id,
                childCourseId: $childCourse['child_course_id'],
                childQuizId: $childCourse['child_quiz_id'],
                childAssignmentId: $childCourse['child_assignment_id'],
                limit: $this->integerOption('limit'),
                metadata: [
                    'trigger_mode' => $triggerMode,
                    'dispatched_by' => 'scheduler',
                    'scheduler_command_run_at' => $now->toIso8601String(),
                    'child_course_id' => $childCourse['child_course_id'],
                    'child_quiz_id' => $childCourse['child_quiz_id'],
                    'child_assignment_id' => $childCourse['child_assignment_id'],
                ],
            );

            if ((bool) $this->option('sync')) {
                app()->call([$job, 'handle']);
                $summary['pipelines_executed_sync']++;
                $rows[] = $this->row($blueprintConfig, $childCourse, 'executed_sync', 'sync');
            } else {
                dispatch($job);
                $summary['pipelines_dispatched']++;
                $rows[] = $this->row($blueprintConfig, $childCourse, 'dispatched', 'queued');
            }
        }

        if ($triggerMode === AiGraderBlueprintConfig::TRIGGER_AFTER_DATE) {
            $this->markAfterDateDispatched($blueprintConfig, $now);
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, AiGraderBlueprintConfig>
     */
    private function blueprints()
    {
        $query = AiGraderBlueprintConfig::query()
            ->with([
                'organization.aiGraderClientSetting',
                'canvasEnvironment',
                'aiProvider',
                'quizConfigs.questionConfigs',
            ])
            ->where('enabled', true);

        if (($organizationId = $this->integerOption('organization-id')) !== null) {
            $query->where('organization_id', $organizationId);
        }

        if (($environmentId = $this->integerOption('environment-id')) !== null) {
            $query->where('canvas_environment_id', $environmentId);
        }

        if (($blueprintConfigId = $this->integerOption('blueprint-config-id')) !== null) {
            $query->whereKey($blueprintConfigId);
        }

        $query->orderBy('id');

        if (($limit = $this->integerOption('limit')) !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }

    private function isEligible(
        AiGraderBlueprintConfig $blueprintConfig,
        AiGraderProviderResolver $providerResolver,
    ): bool
    {
        $setting = $blueprintConfig->organization?->aiGraderClientSetting;

        if (! $setting?->enabled || ! in_array($setting->status, [
            AiGraderClientSetting::STATUS_TRIAL,
            AiGraderClientSetting::STATUS_ACTIVE,
        ], true)) {
            return false;
        }

        if ($blueprintConfig->canvasEnvironment && ! $blueprintConfig->canvasEnvironment->is_active) {
            return false;
        }

        if (! $this->hasEligibleQuizAndQuestion($blueprintConfig)) {
            return false;
        }

        $providerResolver->providerForBlueprint($blueprintConfig);

        return true;
    }

    private function hasEligibleQuizAndQuestion(AiGraderBlueprintConfig $blueprintConfig): bool
    {
        return $blueprintConfig->quizConfigs
            ->contains(function (AiGraderQuizConfig $quizConfig): bool {
                if (! $quizConfig->enabled || ! $quizConfig->correction_enabled) {
                    return false;
                }

                return $quizConfig->questionConfigs
                    ->contains(fn (AiGraderQuestionConfig $questionConfig): bool => $questionConfig->enabled
                        && $questionConfig->canvas_question_type === AiGraderQuestionConfig::TYPE_ESSAY
                        && trim((string) $questionConfig->ai_grading_instructions) !== '');
            });
    }

    private function afterDateAlreadyDispatched(AiGraderBlueprintConfig $blueprintConfig): bool
    {
        $lastDispatchedAt = $this->lastScheduledPipelineDispatchedAt($blueprintConfig);

        return $lastDispatchedAt !== null
            && $blueprintConfig->scheduled_at !== null
            && $lastDispatchedAt->greaterThanOrEqualTo($blueprintConfig->scheduled_at);
    }

    private function lastScheduledPipelineDispatchedAt(AiGraderBlueprintConfig $blueprintConfig): ?Carbon
    {
        $value = data_get($blueprintConfig, 'settings.last_scheduled_pipeline_dispatched_at');

        if (! $value) {
            $value = $blueprintConfig->quizConfigs
                ->map(fn (AiGraderQuizConfig $quizConfig): mixed => data_get($quizConfig->settings, 'last_scheduled_pipeline_dispatched_at'))
                ->filter()
                ->sortDesc()
                ->first();
        }

        return $value ? Carbon::parse((string) $value) : null;
    }

    private function markAfterDateDispatched(AiGraderBlueprintConfig $blueprintConfig, Carbon $now): void
    {
        $value = $now->toIso8601String();

        if (Schema::hasColumn('ai_grader_blueprint_configs', 'settings')) {
            $settings = is_array($blueprintConfig->settings) ? $blueprintConfig->settings : [];
            $settings['last_scheduled_pipeline_dispatched_at'] = $value;
            $blueprintConfig->forceFill(['settings' => $settings])->save();

            return;
        }

        $blueprintConfig->quizConfigs
            ->filter(fn (AiGraderQuizConfig $quizConfig): bool => $quizConfig->enabled && $quizConfig->correction_enabled)
            ->each(function (AiGraderQuizConfig $quizConfig) use ($value): void {
                $settings = is_array($quizConfig->settings) ? $quizConfig->settings : [];
                $settings['last_scheduled_pipeline_dispatched_at'] = $value;
                $quizConfig->forceFill(['settings' => $settings])->save();
            });
    }

    /**
     * @return array<int, array{child_course_id: string, child_quiz_id: string|null, child_assignment_id: string|null}>
     */
    private function childCourses(AiGraderBlueprintConfig $blueprintConfig): array
    {
        $items = collect(data_get($blueprintConfig, 'settings.child_courses', []));

        foreach ($blueprintConfig->quizConfigs as $quizConfig) {
            $items = $items->merge(data_get($quizConfig->settings, 'child_courses', []));
        }

        return $items
            ->filter(fn (mixed $item): bool => is_array($item) && $this->stringOrNull($item['child_course_id'] ?? null) !== null)
            ->map(fn (array $item): array => [
                'child_course_id' => (string) $this->stringOrNull($item['child_course_id'] ?? null),
                'child_quiz_id' => $this->stringOrNull($item['child_quiz_id'] ?? null),
                'child_assignment_id' => $this->stringOrNull($item['child_assignment_id'] ?? null),
            ])
            ->unique(fn (array $item): string => implode('|', [
                $item['child_course_id'],
                (string) $item['child_quiz_id'],
                (string) $item['child_assignment_id'],
            ]))
            ->values()
            ->all();
    }

    /**
     * @param array<string, string|null>|null $childCourse
     * @return array<string, mixed>
     */
    private function row(AiGraderBlueprintConfig $blueprintConfig, ?array $childCourse, string $action, string $reason): array
    {
        return [
            'blueprint_config_id' => $blueprintConfig->id,
            'organization' => $blueprintConfig->organization?->name ?? $blueprintConfig->organization_id,
            'trigger_mode' => $blueprintConfig->trigger_mode,
            'publication_mode' => $blueprintConfig->publication_mode,
            'child_course_id' => $childCourse['child_course_id'] ?? '-',
            'child_quiz_id' => $childCourse['child_quiz_id'] ?? '-',
            'action' => $action,
            'reason' => mb_substr($reason, 0, 120),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function emptySummary(): array
    {
        return [
            'blueprints_evaluated' => 0,
            'blueprints_eligible' => 0,
            'blueprints_skipped' => 0,
            'pipelines_dispatched' => 0,
            'pipelines_executed_sync' => 0,
            'skipped_manual' => 0,
            'skipped_missing_scheduled_at' => 0,
            'skipped_after_date_already_dispatched' => 0,
            'skipped_missing_child_course_mapping' => 0,
            'errors' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        return collect([
            'organization_id' => $this->integerOption('organization-id'),
            'environment_id' => $this->integerOption('environment-id'),
            'blueprint_config_id' => $this->integerOption('blueprint-config-id'),
            'limit' => $this->integerOption('limit'),
        ])->reject(fn (mixed $value): bool => $value === null || $value === '')->all();
    }

    /**
     * @param array<string, int> $summary
     */
    private function renderSummary(array $summary): void
    {
        $this->info('GraderAI scheduled pipeline dispatch summary');
        $this->table(
            ['field', 'value'],
            [
                ['Blueprints avaliadas', $summary['blueprints_evaluated']],
                ['Blueprints elegíveis', $summary['blueprints_eligible']],
                ['Blueprints ignoradas', $summary['blueprints_skipped']],
                ['Pipelines despachados', $summary['pipelines_dispatched']],
                ['Pipelines executados sync', $summary['pipelines_executed_sync']],
                ['Skipped manual', $summary['skipped_manual']],
                ['Skipped missing scheduled_at', $summary['skipped_missing_scheduled_at']],
                ['Skipped after_date already dispatched', $summary['skipped_after_date_already_dispatched']],
                ['Skipped missing child course mapping', $summary['skipped_missing_child_course_mapping']],
                ['Erros', $summary['errors']],
            ],
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function renderRows(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->info('Scheduled pipeline decisions');
        $this->table([
            'blueprint_config_id',
            'organization',
            'trigger_mode',
            'publication_mode',
            'child_course_id',
            'child_quiz_id',
            'action',
            'reason',
        ], $rows);
    }

    private function integerOption(string $key): ?int
    {
        $value = $this->optionValue($key);

        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function optionValue(string $key): ?string
    {
        $value = $this->option($key);

        if ($value === null || $value === false) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
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
