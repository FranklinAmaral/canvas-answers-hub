<?php

declare(strict_types=1);

namespace App\Http\Requests\AiGrader;

use App\Models\AiGraderBlueprintConfig;
use App\Models\AiGraderClientSetting;
use App\Models\AiGraderAiProvider;
use App\Models\CanvasEnvironment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpsertAiGraderBlueprintConfigRequest extends FormRequest
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
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'canvas_environment_id' => ['required', 'integer', 'exists:canvas_environments,id'],
            'blueprint_course_id' => ['required', 'string', 'max:100'],
            'blueprint_course_name' => ['nullable', 'string', 'max:255'],
            'enabled' => ['nullable', 'boolean'],
            'publication_mode' => ['required', Rule::in(AiGraderBlueprintConfig::publicationModes())],
            'trigger_mode' => ['required', Rule::in([
                AiGraderBlueprintConfig::TRIGGER_AFTER_SUBMISSION,
                AiGraderBlueprintConfig::TRIGGER_AFTER_DATE,
                AiGraderBlueprintConfig::TRIGGER_MANUAL,
            ])],
            'scheduled_at' => ['nullable', 'required_if:trigger_mode,' . AiGraderBlueprintConfig::TRIGGER_AFTER_DATE, 'date'],
            'default_feedback_language' => ['required', 'string', 'max:20'],
            'ai_provider_id' => ['nullable', 'integer', 'exists:ai_grader_ai_providers,id'],
            'teacher_can_adjust_score' => ['nullable', 'boolean'],
            'teacher_can_adjust_feedback' => ['nullable', 'boolean'],
            'teacher_can_publish' => ['nullable', 'boolean'],
            'teacher_can_republish' => ['nullable', 'boolean'],
            'teacher_can_export' => ['nullable', 'boolean'],
            'teacher_can_view_grading_prompt' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $organizationId = (int) $this->input('organization_id');
            $environmentId = (int) $this->input('canvas_environment_id');
            $blueprintCourseId = trim((string) $this->input('blueprint_course_id'));
            $enabled = $this->boolean('enabled');
            $current = $this->route('blueprintConfig');
            $currentId = $current instanceof AiGraderBlueprintConfig ? $current->id : null;

            $environmentBelongsToOrganization = CanvasEnvironment::query()
                ->whereKey($environmentId)
                ->where('organization_id', $organizationId)
                ->exists();

            if (! $environmentBelongsToOrganization) {
                $validator->errors()->add('canvas_environment_id', 'O ambiente Canvas deve pertencer à organização selecionada.');
            }

            if ($this->filled('ai_provider_id')) {
                $providerBelongsToOrganization = AiGraderAiProvider::query()
                    ->whereKey((int) $this->input('ai_provider_id'))
                    ->where('organization_id', $organizationId)
                    ->where('enabled', true)
                    ->whereIn('provider_type', AiGraderAiProvider::supportedTypes())
                    ->exists();

                if (! $providerBelongsToOrganization) {
                    $validator->errors()->add('ai_provider_id', 'O provider deve estar ativo, ser suportado e pertencer à organização selecionada.');
                }
            }

            if ($enabled && ! $this->organizationHasAiGraderEnabled($organizationId)) {
                $validator->errors()->add('organization_id', 'GraderAI não está habilitado para esta organização.');
            }

            $duplicate = AiGraderBlueprintConfig::query()
                ->where('organization_id', $organizationId)
                ->where('canvas_environment_id', $environmentId)
                ->where('blueprint_course_id', $blueprintCourseId)
                ->when($currentId !== null, fn ($query) => $query->whereKeyNot($currentId))
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('blueprint_course_id', 'Já existe uma configuração para esta blueprint neste ambiente.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('default_feedback_language')) {
            $this->merge(['default_feedback_language' => 'pt_BR']);
        }
    }

    private function organizationHasAiGraderEnabled(int $organizationId): bool
    {
        return AiGraderClientSetting::query()
            ->where('organization_id', $organizationId)
            ->where('enabled', true)
            ->whereIn('status', [
                AiGraderClientSetting::STATUS_TRIAL,
                AiGraderClientSetting::STATUS_ACTIVE,
            ])
            ->exists();
    }
}
