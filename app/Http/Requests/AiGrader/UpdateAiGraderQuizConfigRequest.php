<?php

declare(strict_types=1);

namespace App\Http\Requests\AiGrader;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAiGraderQuizConfigRequest extends FormRequest
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
            'correction_enabled' => ['nullable', 'boolean'],
        ];
    }
}
