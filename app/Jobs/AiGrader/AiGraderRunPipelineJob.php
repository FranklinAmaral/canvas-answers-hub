<?php

declare(strict_types=1);

namespace App\Jobs\AiGrader;

use App\Models\AiGraderBlueprintConfig;
use App\Services\AiGrader\AiGraderCanvasPublisher;
use App\Services\AiGrader\AiGraderPendingProcessor;
use App\Services\AiGrader\AiGraderSubmissionCollector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AiGraderRunPipelineJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 7200;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly int $blueprintConfigId,
        public readonly ?string $childCourseId = null,
        public readonly ?string $childQuizId = null,
        public readonly ?string $childAssignmentId = null,
        public readonly ?int $providerId = null,
        public readonly ?int $limit = null,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(
        AiGraderSubmissionCollector $collector,
        AiGraderPendingProcessor $processor,
        AiGraderCanvasPublisher $publisher,
    ): array {
        $blueprintConfig = AiGraderBlueprintConfig::query()->findOrFail($this->blueprintConfigId);

        if (! $blueprintConfig->enabled) {
            throw new RuntimeException("AI Grader blueprint config {$this->blueprintConfigId} is disabled.");
        }

        Log::info('AI Grader pipeline job started.', [
            'blueprint_config_id' => $blueprintConfig->id,
            'publication_mode' => $blueprintConfig->publication_mode,
            'filters' => $this->baseFilters($blueprintConfig),
            'metadata' => $this->metadata,
        ]);

        try {
            $collectSummary = $collector->collect($this->collectFilters($blueprintConfig));
            $processSummary = $processor->process($this->processFilters($blueprintConfig));
            $publishSummary = null;

            if ($this->shouldPublish($blueprintConfig)) {
                $publishSummary = $publisher->publish($this->publishFilters($blueprintConfig));
            }

            $summary = [
                'blueprint_config_id' => $blueprintConfig->id,
                'publication_mode' => $blueprintConfig->publication_mode,
                'published' => $publishSummary !== null,
                'collect' => $collectSummary,
                'process' => $processSummary,
                'publish' => $publishSummary,
            ];

            Log::info('AI Grader pipeline job finished.', [
                'summary' => $summary,
                'metadata' => $this->metadata,
            ]);

            return $summary;
        } catch (Throwable $exception) {
            Log::error('AI Grader pipeline job failed.', [
                'blueprint_config_id' => $blueprintConfig->id,
                'message' => $exception->getMessage(),
                'exception' => $exception,
                'metadata' => $this->metadata,
            ]);

            throw $exception;
        }
    }

    private function shouldPublish(AiGraderBlueprintConfig $blueprintConfig): bool
    {
        return AiGraderBlueprintConfig::normalizePublicationMode($blueprintConfig->publication_mode)
            === AiGraderBlueprintConfig::PUBLICATION_AUTO_PUBLISH;
    }

    /**
     * @return array<string, mixed>
     */
    private function collectFilters(AiGraderBlueprintConfig $blueprintConfig): array
    {
        return $this->filled(array_merge($this->baseFilters($blueprintConfig), [
            'child_course_id' => $this->childCourseId,
            'child_quiz_id' => $this->childQuizId,
            'child_assignment_id' => $this->childAssignmentId,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function processFilters(AiGraderBlueprintConfig $blueprintConfig): array
    {
        return $this->filled(array_merge($this->baseFilters($blueprintConfig), [
            'provider_id' => $this->providerId,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function publishFilters(AiGraderBlueprintConfig $blueprintConfig): array
    {
        return $this->filled($this->baseFilters($blueprintConfig));
    }

    /**
     * @return array<string, mixed>
     */
    private function baseFilters(AiGraderBlueprintConfig $blueprintConfig): array
    {
        return $this->filled([
            'organization_id' => $blueprintConfig->organization_id,
            'environment_id' => $blueprintConfig->canvas_environment_id,
            'blueprint_config_id' => $blueprintConfig->id,
            'limit' => $this->limit,
        ]);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function filled(array $values): array
    {
        return collect($values)
            ->reject(fn (mixed $value): bool => $value === null || $value === '')
            ->all();
    }
}
