<?php

declare(strict_types=1);

namespace App\Http\Requests\AiGrader;

use App\Models\AiGraderQuestionConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAiGraderQuestionConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['nullable', 'boolean'],
            'ai_grading_instructions' => ['nullable', 'string', 'min:30'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $questionConfig = $this->route('questionConfig');
            $enabled = $this->boolean('enabled');
            $instructions = trim((string) $this->input('ai_grading_instructions', ''));

            if (! $questionConfig instanceof AiGraderQuestionConfig) {
                return;
            }

            if ($enabled && $questionConfig->canvas_question_type !== AiGraderQuestionConfig::TYPE_ESSAY) {
                $validator->errors()->add('enabled', 'Questões objetivas não podem ser habilitadas para correção por IA.');
            }

            if ($enabled && $instructions === '') {
                $validator->errors()->add('ai_grading_instructions', 'Informe as orientações de correção para IA.');
            }
        });
    }
}
