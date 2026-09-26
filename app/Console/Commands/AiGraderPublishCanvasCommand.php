<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AiGrader\AiGraderCanvasPublisher;
use Illuminate\Console\Command;

class AiGraderPublishCanvasCommand extends Command
{
    protected $signature = 'ai-grader:publish-canvas
        {--organization-id= : Filter by organization ID}
        {--environment-id= : Filter by Canvas environment ID}
        {--blueprint-config-id= : Filter by GraderAI blueprint config ID}
        {--quiz-config-id= : Filter by GraderAI quiz config ID}
        {--item-id= : Publish a single correction item}
        {--limit= : Limit correction items published in this run}
        {--dry-run : Simulate without calling Canvas or saving publication results}';

    protected $description = 'Publish GraderAI corrected Classic Quiz essay scores and feedback to Canvas.';

    public function handle(AiGraderCanvasPublisher $publisher): int
    {
        $summary = $publisher->publish([
            'organization_id' => $this->optionValue('organization-id'),
            'environment_id' => $this->optionValue('environment-id'),
            'blueprint_config_id' => $this->optionValue('blueprint-config-id'),
            'quiz_config_id' => $this->optionValue('quiz-config-id'),
            'item_id' => $this->optionValue('item-id'),
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
        $this->info('GraderAI Canvas publication summary');
        $this->table(
            ['field', 'value'],
            [
                ['Itens encontrados', $summary['items_found']],
                ['Itens publicados', $summary['published']],
                ['Itens ignorados', $summary['ignored']],
                ['Itens falharam', $summary['failed']],
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
            $this->warn('No correction items were found for Canvas publication.');

            return;
        }

        $this->info('Publication items');
        $this->table(
            [
                'item_id',
                'aluno',
                'question_id',
                'score',
                'points_possible',
                'status anterior',
                'status novo',
                'canvas_quiz_submission_id',
                'endpoint',
                'resultado',
                'mensagem',
            ],
            collect($items)->map(fn (array $item): array => [
                $item['item_id'],
                $this->truncate((string) $item['student'], 35),
                $item['question_id'],
                $item['score'],
                $item['points_possible'],
                $item['old_status'],
                $item['new_status'],
                $item['canvas_quiz_submission_id'],
                $this->truncate((string) $item['endpoint'], 70),
                $item['canvas_result'],
                $this->truncate((string) $item['message'], 120),
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
