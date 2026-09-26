<?php

declare(strict_types=1);

namespace App\Http\Requests\AiGrader;

use App\Models\AiGraderClientSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAiGraderClientSettingRequest extends FormRequest
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
            'status' => ['required', Rule::in([
                AiGraderClientSetting::STATUS_INACTIVE,
                AiGraderClientSetting::STATUS_TRIAL,
                AiGraderClientSetting::STATUS_ACTIVE,
                AiGraderClientSetting::STATUS_SUSPENDED,
                AiGraderClientSetting::STATUS_QUOTA_EXCEEDED,
            ])],
            'plan_name' => ['nullable', 'string', 'max:255'],
            'trial_quota_total' => ['nullable', 'integer', 'min:0'],
            'purchased_quota_total' => ['required', 'integer', 'min:0'],
            'quota_used' => ['required', 'integer', 'min:0'],
            'quota_reserved' => ['required', 'integer', 'min:0'],
            'quota_reset_at' => ['nullable', 'date'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'notes' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('status') === AiGraderClientSetting::STATUS_TRIAL && ! $this->filled('trial_quota_total')) {
            $this->merge(['trial_quota_total' => 50]);
        }
    }
}
