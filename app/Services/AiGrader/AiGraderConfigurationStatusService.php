<?php

declare(strict_types=1);

namespace App\Services\AiGrader;

use App\Models\AiGraderBlueprintConfig;
use App\Models\AiGraderQuestionConfig;
use App\Models\AiGraderQuizConfig;

class AiGraderConfigurationStatusService
{
    /**
     * @return array<string, int|bool>
     */
    public function forBlueprint(AiGraderBlueprintConfig $blueprintConfig): array
    {
        $blueprintConfig->loadMissing('quizConfigs.questionConfigs');

        $quizCount = $blueprintConfig->quizConfigs->count();
        $questions = $blueprintConfig->quizConfigs->flatMap(
            fn (AiGraderQuizConfig $quizConfig) => $quizConfig->questionConfigs
        );
        $enabledQuizzes = $blueprintConfig->quizConfigs->filter(
            fn (AiGraderQuizConfig $quizConfig): bool => $quizConfig->enabled && $quizConfig->correction_enabled
        );

        $enabledEssayQuestions = $questions->filter(
            fn (AiGraderQuestionConfig $questionConfig): bool => $questionConfig->enabled
                && $questionConfig->canvas_question_type === AiGraderQuestionConfig::TYPE_ESSAY
        );

        $enabledWithoutInstructions = $enabledEssayQuestions->filter(
            fn (AiGraderQuestionConfig $questionConfig): bool => trim((string) $questionConfig->ai_grading_instructions) === ''
        );
        $enabledWithoutRubric = $enabledEssayQuestions->filter(
            fn (AiGraderQuestionConfig $questionConfig): bool => $questionConfig->usesCanvasRubric() && ! $questionConfig->hasRubricSnapshot()
        );

        return [
            'quizzes' => $quizCount,
            'essay_questions' => $questions->where('canvas_question_type', AiGraderQuestionConfig::TYPE_ESSAY)->count(),
            'enabled_essay_questions' => $enabledEssayQuestions->count(),
            'questions_without_instructions' => $enabledWithoutInstructions->count(),
            'questions_without_rubric' => $enabledWithoutRubric->count(),
            'correction_items' => $blueprintConfig->correctionItems()->count(),
            'ready' => $blueprintConfig->enabled
                && $enabledQuizzes->isNotEmpty()
                && $enabledEssayQuestions->isNotEmpty()
                && $enabledWithoutInstructions->isEmpty()
                && $enabledWithoutRubric->isEmpty()
                && $enabledQuizzes->every(fn (AiGraderQuizConfig $quizConfig): bool => trim((string) $quizConfig->canvas_assignment_id) !== ''),
        ];
    }

    /**
     * @return array<string, int|bool>
     */
    public function forQuiz(AiGraderQuizConfig $quizConfig): array
    {
        $quizConfig->loadMissing('questionConfigs');

        $essayQuestions = $quizConfig->questionConfigs->where('canvas_question_type', AiGraderQuestionConfig::TYPE_ESSAY);
        $enabledEssayQuestions = $essayQuestions->where('enabled', true);
        $enabledWithoutInstructions = $enabledEssayQuestions->filter(
            fn (AiGraderQuestionConfig $questionConfig): bool => trim((string) $questionConfig->ai_grading_instructions) === ''
        );
        $enabledWithoutRubric = $enabledEssayQuestions->filter(
            fn (AiGraderQuestionConfig $questionConfig): bool => $questionConfig->usesCanvasRubric() && ! $questionConfig->hasRubricSnapshot()
        );

        return [
            'essay_questions' => $essayQuestions->count(),
            'enabled_essay_questions' => $enabledEssayQuestions->count(),
            'enabled_without_instructions' => $enabledWithoutInstructions->count(),
            'enabled_without_rubric' => $enabledWithoutRubric->count(),
            'objective_questions' => $quizConfig->questionConfigs
                ->where('canvas_question_type', '!=', AiGraderQuestionConfig::TYPE_ESSAY)
                ->count(),
            'ready' => $quizConfig->enabled
                && $quizConfig->correction_enabled
                && trim((string) $quizConfig->canvas_assignment_id) !== ''
                && $enabledEssayQuestions->isNotEmpty()
                && $enabledWithoutInstructions->isEmpty()
                && $enabledWithoutRubric->isEmpty(),
        ];
    }
}
