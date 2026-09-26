<?php

declare(strict_types=1);

namespace App\Jobs\AiGrader;

use App\Services\AiGrader\AiGraderCanvasPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiGraderPublishCorrectedItemsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 3600;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly ?int $organizationId = null,
        public readonly ?int $environmentId = null,
        public readonly ?int $blueprintConfigId = null,
        public readonly ?int $quizConfigId = null,
        public readonly ?int $limit = null,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(AiGraderCanvasPublisher $publisher): array
    {
        $filters = $this->filters();

        Log::info('AI Grader publish corrected items job started.', [
            'filters' => $filters,
            'metadata' => $this->metadata,
        ]);

        try {
            $summary = $publisher->publish($filters);

            Log::info('AI Grader publish corrected items job finished.', [
                'filters' => $filters,
                'summary' => $summary,
                'metadata' => $this->metadata,
            ]);

            return $summary;
        } catch (Throwable $exception) {
            Log::error('AI Grader publish corrected items job failed.', [
                'filters' => $filters,
                'message' => $exception->getMessage(),
                'exception' => $exception,
                'metadata' => $this->metadata,
            ]);

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        return $this->filled([
            'organization_id' => $this->organizationId,
            'environment_id' => $this->environmentId,
            'blueprint_config_id' => $this->blueprintConfigId,
            'quiz_config_id' => $this->quizConfigId,
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
