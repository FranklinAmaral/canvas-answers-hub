<?php

declare(strict_types=1);

namespace App\Services\AiGrader\Providers;

use App\Models\AiGraderAiProvider;
use App\Models\AiGraderCorrectionItem;
use App\Models\AiGraderQuestionConfig;
use App\Services\AiGrader\Contracts\AiGraderProviderInterface;
use App\Services\AiGrader\Data\AiGraderPromptContext;
use App\Services\AiGrader\Data\AiGraderResult;

class MockAiGraderProvider implements AiGraderProviderInterface
{
    public function __construct(
        private readonly ?AiGraderAiProvider $provider = null,
    ) {
    }

    public function grade(AiGraderCorrectionItem $item, AiGraderPromptContext $context): AiGraderResult
    {
        $answer = trim($context->answerText);

        if ($answer === '') {
            return AiGraderResult::rejected('blank_answer', $this->providerMetadata() + [
                'provider' => 'mock',
                'reason' => 'blank_answer',
            ]);
        }

        $answerLength = strlen($answer);
        $pointsPossible = max(0.0, $context->pointsPossible);
        $factor = match (true) {
            $answerLength < 100 => 0.25,
            $answerLength <= 500 => 0.50,
            default => 0.75,
        };

        $score = $pointsPossible * $factor;

        if ($context->correctionMode === AiGraderQuestionConfig::CORRECTION_MODE_CANVAS_RUBRIC && ($context->rubricSnapshot['criteria'] ?? []) !== []) {
            $rubricFeedback = $this->rubricFeedback($context, $factor * 100);

            return new AiGraderResult(
                score: $score,
                feedback: sprintf(
                    'Correcao simulada/mock com rubrica: resposta com %d caracteres. Resultado geral estimado em %.0f%% da pontuacao maxima.',
                    $answerLength,
                    $factor * 100,
                ),
                rubricPercentage: $factor * 100,
                rubricFeedback: $rubricFeedback,
                confidence: 'mock',
                reviewFlags: [
                    'mock_provider' => true,
                    'rubric_mode' => true,
                ],
                rawResponse: $this->providerMetadata() + [
                    'provider' => 'mock',
                    'answer_length' => $answerLength,
                    'score_factor' => $factor,
                    'points_possible' => $pointsPossible,
                    'rubric_feedback' => $rubricFeedback,
                ],
            );
        }

        return new AiGraderResult(
            score: $score,
            feedback: sprintf(
                'Correcao simulada/mock: resposta com %d caracteres. Criterio mock aplicado: %.0f%% da pontuacao maxima.',
                $answerLength,
                $factor * 100,
            ),
            confidence: 'mock',
            reviewFlags: [
                'mock_provider' => true,
            ],
            rawResponse: $this->providerMetadata() + [
                'provider' => 'mock',
                'answer_length' => $answerLength,
                'score_factor' => $factor,
                'points_possible' => $pointsPossible,
            ],
        );
    }

    /**
     * @return array<string, int>
     */
    private function providerMetadata(): array
    {
        return $this->provider?->id !== null
            ? ['ai_provider_id' => (int) $this->provider->id]
            : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rubricFeedback(AiGraderPromptContext $context, float $percentage): array
    {
        $criteria = $context->rubricSnapshot['criteria'] ?? [];

        if (! is_array($criteria)) {
            return [];
        }

        $feedback = [];

        foreach ($criteria as $criterion) {
            if (! is_array($criterion)) {
                continue;
            }

            $feedback[] = [
                'criterion_id' => (string) ($criterion['id'] ?? ''),
                'criterion_name' => (string) ($criterion['name'] ?? $criterion['description'] ?? $criterion['id'] ?? 'Criterio'),
                'percentage' => round($percentage, 2),
                'max_percentage' => is_numeric($criterion['max_percentage'] ?? null) ? round((float) $criterion['max_percentage'], 2) : null,
                'max_points' => is_numeric($criterion['max_points'] ?? null) ? round((float) $criterion['max_points'], 2) : null,
                'feedback' => 'Analise simulada pelo provider mock para este criterio.',
            ];
        }

        return $feedback;
    }
}
