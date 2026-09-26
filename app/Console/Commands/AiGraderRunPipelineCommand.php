<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\AiGrader\AiGraderRunPipelineJob;
use App\Models\AiGraderAiProvider;
use Illuminate\Console\Command;

class AiGraderRunPipelineCommand extends Command
{
    protected $signature = 'ai-grader:run-pipeline
        {--blueprint-config-id= : GraderAI blueprint config ID}
        {--child-course-id= : Canvas child course ID to collect from}
        {--child-quiz-id= : Canvas child Classic Quiz ID override}
        {--child-assignment-id= : Canvas child assignment ID override}
        {--provider-id= : Use a specific AI provider only for this run}
        {--limit= : Limit items collected, processed and published in this run}
        {--sync : Execute immediately instead of dispatching a queue job}';

    protected $description = 'Run the GraderAI collection, processing and publication pipeline.';

    public function handle(): int
    {
        $blueprintConfigId = $this->integerOption('blueprint-config-id');

        if ($blueprintConfigId === null) {
            $this->error('The --blueprint-config-id option is required.');

            return self::FAILURE;
        }

        $providerId = $this->integerOption('provider-id');

        if ($providerId !== null && ! AiGraderAiProvider::query()->whereKey($providerId)->exists()) {
            $this->error("Provider ID {$providerId} não encontrado.");

            return self::FAILURE;
        }

        $job = new AiGraderRunPipelineJob(
            blueprintConfigId: $blueprintConfigId,
            childCourseId: $this->optionValue('child-course-id'),
            childQuizId: $this->optionValue('child-quiz-id'),
            childAssignmentId: $this->optionValue('child-assignment-id'),
            providerId: $providerId,
            limit: $this->integerOption('limit'),
            metadata: ['trigger' => 'artisan'],
        );

        if (! (bool) $this->option('sync')) {
            dispatch($job);
            $this->info('GraderAI pipeline job dispatched.');

            return self::SUCCESS;
        }

        $summary = app()->call([$job, 'handle']);
        $this->renderSummary($summary);

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function renderSummary(array $summary): void
    {
        $this->info('GraderAI pipeline summary');
        $this->table(
            ['field', 'value'],
            [
                ['Blueprint config ID', $summary['blueprint_config_id']],
                ['Publication mode', $summary['publication_mode']],
                ['Collect pending created', data_get($summary, 'collect.pending_created', 0)],
                ['Collect essay answers found', data_get($summary, 'collect.essay_answers_found', 0)],
                ['Process ai_corrected', data_get($summary, 'process.ai_corrected', 0)],
                ['Process pending teacher approval', data_get($summary, 'process.pending_teacher_approval', 0)],
                ['Process failed', data_get($summary, 'process.failed', 0)],
                ['Publish executed', $summary['published'] ? 'sim' : 'não'],
                ['Publish published', data_get($summary, 'publish.published', 0)],
                ['Publish failed', data_get($summary, 'publish.failed', 0)],
            ],
        );
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
}
