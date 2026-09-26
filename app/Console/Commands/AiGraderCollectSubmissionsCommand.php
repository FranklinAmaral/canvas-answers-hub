<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AiGrader\AiGraderSubmissionCollector;
use Illuminate\Console\Command;

class AiGraderCollectSubmissionsCommand extends Command
{
    protected $signature = 'ai-grader:collect-submissions
        {--organization-id= : Filter by organization ID}
        {--environment-id= : Filter by Canvas environment ID}
        {--blueprint-config-id= : Filter by GraderAI blueprint config ID}
        {--quiz-config-id= : Filter by GraderAI quiz config ID}
        {--child-course-id= : Canvas child course ID to collect from}
        {--child-quiz-id= : Canvas child Classic Quiz ID override}
        {--child-assignment-id= : Canvas child assignment ID override}
        {--dry-run : Simulate without creating batches or correction items}
        {--limit= : Limit new correction items created in this run}';

    protected $description = 'Collect Canvas Classic Quiz essay submissions for GraderAI.';

    public function handle(AiGraderSubmissionCollector $collector): int
    {
        $summary = $collector->collect([
            'organization_id' => $this->optionValue('organization-id'),
            'environment_id' => $this->optionValue('environment-id'),
            'blueprint_config_id' => $this->optionValue('blueprint-config-id'),
            'quiz_config_id' => $this->optionValue('quiz-config-id'),
            'child_course_id' => $this->optionValue('child-course-id'),
            'child_quiz_id' => $this->optionValue('child-quiz-id'),
            'child_assignment_id' => $this->optionValue('child-assignment-id'),
            'dry_run' => (bool) $this->option('dry-run'),
            'limit' => $this->optionValue('limit'),
        ]);

        $this->renderSummary($summary);
        $this->renderQuestionMappings($summary['question_mappings']);
        $this->renderItems($summary['items']);

        return $summary['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function renderSummary(array $summary): void
    {
        $this->info('GraderAI submission collection summary');

        $this->table(
            ['field', 'value'],
            [
                ['Configurações encontradas', $summary['configurations_found']],
                ['Configurações ignoradas', $summary['skipped_configuration']],
                ['Quizzes processados', $summary['quizzes_processed']],
                ['Cursos filhos processados', count($summary['child_courses_processed'])],
                ['Submissões encontradas', $summary['submissions_found']],
                ['Submissões elegíveis', $summary['submissions_eligible']],
                ['Questões mapeadas', $summary['questions_mapped']],
                ['Questões sem mapeamento', $summary['question_mapping_failed']],
                ['Estratégia de mapeamento usada', $this->mappingStrategies($summary['question_mapping_strategy_counts'])],
                ['Mapeamentos de curso filho persistidos', $summary['child_course_mappings_persisted']],
                ['Mapeamentos de curso filho atualizados', $summary['child_course_mappings_updated']],
                ['Respostas discursivas encontradas', $summary['essay_answers_found']],
                ['Itens pending criados', $summary['pending_created']],
                ['Itens pending_quota criados', $summary['pending_quota_created']],
                ['Itens skipped_blank_answer', $summary['skipped_blank_answer']],
                ['Itens skipped_already_processed', $summary['skipped_already_processed']],
                ['Itens pending_configuration', $summary['pending_configuration_created']],
                ['Erros', count($summary['errors'])],
            ],
        );

        if ($summary['errors'] !== []) {
            $this->warn('Errors');

            foreach ($summary['errors'] as $error) {
                $this->line('- ' . $error);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $mappings
     */
    private function renderQuestionMappings(array $mappings): void
    {
        if ($mappings === []) {
            return;
        }

        $this->info('Question mapping');
        $this->table(
            [
                'blueprint_question_id',
                'blueprint_question_name',
                'child_question_id',
                'child_question_name',
                'type',
                'strategy',
                'status',
            ],
            $mappings,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function renderItems(array $items): void
    {
        if ($items === []) {
            $this->warn('No correction items were created.');

            return;
        }

        $this->info('Correction items');
        $this->table(
            [
                'correction_item_id',
                'status',
                'user_id',
                'user_name',
                'quiz_submission_id',
                'attempt',
                'question_id',
                'answer_length',
                'quota_reserved',
            ],
            $items,
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

    /**
     * @param array<string, int> $strategies
     */
    private function mappingStrategies(array $strategies): string
    {
        if ($strategies === []) {
            return '-';
        }

        return collect($strategies)
            ->map(fn (int $count, string $strategy): string => "{$strategy}: {$count}")
            ->implode(', ');
    }
}
