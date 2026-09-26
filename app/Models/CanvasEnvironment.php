<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CanvasEnvironment extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'environment_type',
        'base_url',
        'api_token',
        'is_active',
        'is_default',
        'lti_enabled',
        'lti_issuer',
        'lti_client_id',
        'lti_deployment_id',
        'lti_authorization_endpoint',
        'lti_jwks_uri',
    ];

    protected $casts = [
        'api_token' => 'encrypted',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'lti_enabled' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->environment_type) {
            'production' => 'Produção',
            'test' => 'Teste',
            default => ucfirst($this->environment_type),
        };
    }

    public function getTypeBadgeClassAttribute(): string
    {
        return match ($this->environment_type) {
            'production' => 'danger',
            'test' => 'primary',
            default => 'danger',
        };
    }

    /**
     * @return array{configured: bool, invalid: bool}
     */
    public function apiTokenStatus(): array
    {
        $rawToken = $this->getRawOriginal('api_token');

        if (! is_string($rawToken) || trim($rawToken) === '') {
            return [
                'configured' => false,
                'invalid' => false,
            ];
        }

        try {
            $token = $this->getAttribute('api_token');
        } catch (DecryptException) {
            return [
                'configured' => true,
                'invalid' => true,
            ];
        }

        return [
            'configured' => is_string($token) && trim($token) !== '',
            'invalid' => false,
        ];
    }

    public function replaceApiToken(string $token): void
    {
        $tokenOnlyEnvironment = self::query()
            ->select([$this->getKeyName()])
            ->findOrFail($this->getKey());

        $tokenOnlyEnvironment->api_token = trim($token);
        $tokenOnlyEnvironment->save();
    }
}
