<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiGraderAiProvider;
use App\Services\AiGrader\AiGraderPendingProcessor;
use Illuminate\Console\Command;

class AiGraderProcessPendingCommand extends Command
{
    protected $signature = 'ai-grader:process-pending
        {--organization-id= : Filter by organization ID}
        {--environment-id= : Filter by Canvas environment ID}
        {--blueprint-config-id= : Filter by GraderAI blueprint config ID}
        {--quiz-config-id= : Filter by GraderAI quiz config ID}
        {--item-id= : Process a single correction item}
        {--provider-id= : Use a specific AI provider only for this run}
        {--limit= : Limit correction items processed in this run}
        {--dry-run : Simulate without saving correction results or changing quota}';

    protected $description = 'Process pending GraderAI correction items with the configured AI provider.';

    public function handle(AiGraderPendingProcessor $processor): int
    {
        $providerId = $this->optionValue('provider-id');

        if ($providerId !== null && ! AiGraderAiProvider::query()->whereKey((int) $providerId)->exists()) {
            $this->error("Provider ID {$providerId} não encontrado.");

            return self::FAILURE;
        }

        $summary = $processor->process([
            'organization_id' => $this->optionValue('organization-id'),
            'environment_id' => $this->optionValue('environment-id'),
            'blueprint_config_id' => $this->optionValue('blueprint-config-id'),
            'quiz_config_id' => $this->optionValue('quiz-config-id'),
            'item_id' => $this->optionValue('item-id'),
            'provider_id' => $providerId,
            'limit' => $this->optionValue('limit'),
            'dry_run' => (bool) $this->option('dry-run'),
        ]);

        $this->renderSummary($summary);
        $this->renderItems($summary['items']);

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function renderSummary(array $summary): void
    {
        $this->info('GraderAI pending processing summary');
        $this->table(
            ['field', 'value'],
            [
                ['Itens encontrados', $summary['items_found']],
                ['Itens processados com sucesso', $summary['processed_success']],
                ['Itens pendentes para aprovação professor', $summary['pending_teacher_approval']],
                ['Itens corrigidos pela IA', $summary['ai_corrected']],
                ['Itens rejeitados pela IA', $summary['ai_rejected']],
                ['Itens falharam', $summary['failed']],
                ['Cotas consumidas', $summary['quota_consumed']],
                ['Cotas liberadas', $summary['quota_released']],
                ['Dry-run', $summary['dry_run'] ? 'sim' : 'não'],
            ],
        );
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function renderItems(array $items): void
    {
        if ($items === []) {
            $this->warn('No pending correction items were found.');

            return;
        }

        $this->info('Processed items');
        $this->table(
            [
                'item_id',
                'aluno',
                'question_id',
                'status anterior',
                'status novo',
                'ai_score',
                'points_possible',
                'feedback_sample',
                'quota_action',
            ],
            collect($items)->map(fn (array $item): array => [
                $item['item_id'],
                $this->truncate((string) $item['student'], 35),
                $item['question_id'],
                $item['old_status'],
                $item['new_status'],
                $item['ai_score'],
                $item['points_possible'],
                $this->truncate((string) $item['feedback_sample'], 80),
                $item['quota_action'],
            ])->all(),
        );
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

    private function truncate(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, max(0, $limit - 3)) . '...';
    }
}
