<?php

declare(strict_types=1);

use App\Models\AiGraderCorrectionItem;
use App\Models\AiGraderQuestionConfig;
use App\Services\AiGrader\Data\AiGraderPromptContext;
use App\Services\AiGrader\Providers\MockAiGraderProvider;

it('uses the feedback language independently of the interface locale', function (string $language, string $interfaceLocale, string $expectedFeedback, string $expectedCriterion, bool $withRubric): void {
    app()->setLocale($interfaceLocale);
    $context = new AiGraderPromptContext(
        questionText: 'Explain the concept.',
        answerText: str_repeat('A', 200),
        pointsPossible: 10,
        gradingInstructions: 'Use the supplied criteria.',
        feedbackLanguage: $language,
        correctionMode: $withRubric ? AiGraderQuestionConfig::CORRECTION_MODE_CANVAS_RUBRIC : AiGraderQuestionConfig::CORRECTION_MODE_SIMPLE,
        rubricSnapshot: $withRubric ? ['criteria' => [['id' => 'criterion-1', 'name' => 'Understanding']]] : [],
    );

    $result = (new MockAiGraderProvider)->grade(new AiGraderCorrectionItem, $context);

    expect($result->score)->toBe(5.0)
        ->and($result->rejected)->toBeFalse()
        ->and($result->feedback)->toStartWith($expectedFeedback)
        ->and($result->feedback)->toContain('200', '50%')
        ->and($result->reviewFlags['mock_provider'])->toBeTrue()
        ->and(app()->getLocale())->toBe($interfaceLocale);

    if ($withRubric) {
        expect($result->rubricPercentage)->toBe(50.0)
            ->and($result->rubricFeedback[0]['criterion_id'])->toBe('criterion-1')
            ->and($result->rubricFeedback[0]['feedback'])->toBe($expectedCriterion);
    } else {
        expect($result->rubricFeedback)->toBe([]);
    }
})->with([
    'Portuguese simple' => ['pt_BR', 'en', 'Correção simulada/mock:', 'Análise simulada pelo provider mock para este critério.', false],
    'English simple' => ['en', 'pt_BR', 'Simulated/mock grading:', 'Simulated analysis by the mock provider for this criterion.', false],
    'Portuguese rubric' => ['pt_BR', 'en', 'Correção simulada/mock com rubrica:', 'Análise simulada pelo provider mock para este critério.', true],
    'English rubric' => ['en', 'pt_BR', 'Simulated/mock rubric grading:', 'Simulated analysis by the mock provider for this criterion.', true],
]);
