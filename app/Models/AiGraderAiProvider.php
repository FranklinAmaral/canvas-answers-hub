<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiGraderAiProvider extends Model
{
    protected $table = 'ai_grader_ai_providers';

    public const TYPE_MOCK = 'mock';
    public const TYPE_LM_STUDIO = 'lm_studio';

    /**
     * @return array<int, string>
     */
    public static function supportedTypes(): array
    {
        return [
            self::TYPE_MOCK,
            self::TYPE_LM_STUDIO,
        ];
    }

    protected $fillable = [
        'organization_id',
        'name',
        'provider_type',
        'base_url',
        'model',
        'api_key_encrypted',
        'request_timeout_seconds',
        'max_tokens',
        'temperature',
        'input_token_cost',
        'output_token_cost',
        'enabled',
        'is_default',
        'settings',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'api_key_encrypted',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'api_key_encrypted' => 'encrypted',
        'request_timeout_seconds' => 'integer',
        'max_tokens' => 'integer',
        'temperature' => 'decimal:2',
        'input_token_cost' => 'decimal:6',
        'output_token_cost' => 'decimal:6',
        'enabled' => 'boolean',
        'is_default' => 'boolean',
        'settings' => 'array',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function blueprintConfigs(): HasMany
    {
        return $this->hasMany(AiGraderBlueprintConfig::class, 'ai_provider_id');
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(AiGraderUsageLog::class, 'ai_provider_id');
    }

    public function endpointPath(): string
    {
        $path = trim((string) data_get($this->settings, 'endpoint_path', '/v1/chat/completions'));

        return $path !== '' ? $path : '/v1/chat/completions';
    }

    public function endpointUrl(): string
    {
        return rtrim((string) $this->base_url, '/') . '/' . ltrim($this->endpointPath(), '/');
    }

    public function hasApiKey(): bool
    {
        return filled($this->getRawOriginal('api_key_encrypted'));
    }
}
