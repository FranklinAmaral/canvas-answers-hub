<?php

declare(strict_types=1);

namespace App\Jobs\AiGrader;

use App\Models\AiGraderBlueprintConfig;
use App\Models\AiGraderCorrectionItem;
use App\Services\AiGrader\AiGraderCanvasPublisher;
use App\Services\AiGrader\AiGraderCorrectionRetryService;
use App\Services\AiGrader\AiGraderPendingProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiGraderRetryCorrectionItemJob implements ShouldQueue
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
        public readonly int $correctionItemId,
        public readonly string $retryType,
        public readonly array $metadata = [],
    ) {
    }

    public function handle(AiGraderPendingProcessor $processor, AiGraderCanvasPublisher $publisher): void
    {
        Log::info('AI Grader retry correction item job started.', [
            'correction_item_id' => $this->correctionItemId,
            'retry_type' => $this->retryType,
            'metadata' => $this->metadata,
        ]);

        try {
            $item = AiGraderCorrectionItem::query()->find($this->correctionItemId);

            if (! $item instanceof AiGraderCorrectionItem) {
                Log::warning('AI Grader retry correction item job ignored because the item was not found.', [
                    'correction_item_id' => $this->correctionItemId,
                    'retry_type' => $this->retryType,
                    'metadata' => $this->metadata,
                ]);

                return;
            }

            if ($this->retryType === AiGraderCorrectionRetryService::RETRY_AI) {
                $processor->process(['item_id' => $item->id]);
                $item = $item->fresh();

                if ($item instanceof AiGraderCorrectionItem
                    && $item->status === AiGraderCorrectionItem::STATUS_AI_CORRECTED
                    && AiGraderBlueprintConfig::normalizePublicationMode($item->publication_mode) === AiGraderBlueprintConfig::PUBLICATION_AUTO_PUBLISH) {
                    $publisher->publish(['item_id' => $item->id]);
                }

                return;
            }

            if ($this->retryType === AiGraderCorrectionRetryService::RETRY_PUBLICATION) {
                $item->refresh();

                if (AiGraderBlueprintConfig::normalizePublicationMode($item->publication_mode) === AiGraderBlueprintConfig::PUBLICATION_AUTO_PUBLISH) {
                    $publisher->publish(['item_id' => $item->id]);

                    return;
                }

                $publisher->publishTeacherReviewedItem($item);

                return;
            }

            Log::warning('AI Grader retry correction item job received an unsupported retry type.', [
                'correction_item_id' => $this->correctionItemId,
                'retry_type' => $this->retryType,
                'metadata' => $this->metadata,
            ]);
        } catch (Throwable $exception) {
            Log::error('AI Grader retry correction item job failed.', [
                'correction_item_id' => $this->correctionItemId,
                'retry_type' => $this->retryType,
                'message' => $exception->getMessage(),
                'exception' => $exception,
                'metadata' => $this->metadata,
            ]);

            throw $exception;
        }
    }
}
