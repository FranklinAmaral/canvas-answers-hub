<?php

declare(strict_types=1);

namespace App\Http\Requests\AiGrader;

use App\Models\AiGraderAiProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpsertAiGraderAiProviderRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'provider_type' => ['required', Rule::in(AiGraderAiProvider::supportedTypes())],
            'base_url' => ['nullable', 'required_if:provider_type,' . AiGraderAiProvider::TYPE_LM_STUDIO, 'url', 'max:255'],
            'endpoint_path' => ['nullable', 'required_if:provider_type,' . AiGraderAiProvider::TYPE_LM_STUDIO, 'string', 'max:255'],
            'model' => ['nullable', 'required_if:provider_type,' . AiGraderAiProvider::TYPE_LM_STUDIO, 'string', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:2000'],
            'request_timeout_seconds' => ['nullable', 'integer', 'min:5', 'max:300'],
            'max_tokens' => ['nullable', 'integer', 'min:100'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'enabled' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('is_default') && ! $this->boolean('enabled')) {
                $validator->errors()->add('is_default', 'O provider padrão deve estar ativo.');
            }

            if ($this->input('provider_type') === AiGraderAiProvider::TYPE_MOCK && ! app()->environment(['local', 'testing'])) {
                $validator->errors()->add('provider_type', 'O provider Mock só pode ser usado em desenvolvimento e testes.');
            }

            if ($this->input('provider_type') === AiGraderAiProvider::TYPE_LM_STUDIO) {
                $baseUrl = trim((string) $this->input('base_url'));
                $scheme = mb_strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
                $endpointPath = trim((string) $this->input('endpoint_path'));

                if (! in_array($scheme, ['http', 'https'], true)) {
                    $validator->errors()->add('base_url', 'A URL base deve usar HTTP ou HTTPS.');
                }

                if (
                    parse_url($baseUrl, PHP_URL_USER) !== null
                    || parse_url($baseUrl, PHP_URL_PASS) !== null
                    || parse_url($baseUrl, PHP_URL_QUERY) !== null
                    || parse_url($baseUrl, PHP_URL_FRAGMENT) !== null
                ) {
                    $validator->errors()->add('base_url', 'A URL base não pode conter credenciais, query string ou fragmento.');
                }

                if (! str_starts_with($endpointPath, '/') || str_starts_with($endpointPath, '//')) {
                    $validator->errors()->add('endpoint_path', 'O endpoint path deve iniciar com uma única barra.');
                }
            }

            $provider = $this->route('provider');

            if (
                $provider instanceof AiGraderAiProvider
                && (int) $provider->organization_id !== (int) $this->input('organization_id')
                && $provider->blueprintConfigs()->exists()
            ) {
                $validator->errors()->add('organization_id', 'A organização não pode ser alterada enquanto o provider estiver associado a blueprints.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('endpoint_path') && $this->input('provider_type') === AiGraderAiProvider::TYPE_LM_STUDIO) {
            $this->merge(['endpoint_path' => '/v1/chat/completions']);
        }
    }
}
