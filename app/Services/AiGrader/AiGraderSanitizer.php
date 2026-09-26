<?php

declare(strict_types=1);

namespace App\Services\AiGrader;

class AiGraderSanitizer
{
    private const MASK = '[hidden]';

    /**
     * @param array<string|int, mixed> $payload
     * @return array<string|int, mixed>
     */
    public function sanitizeArray(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            $keyString = is_string($key) ? $key : (string) $key;

            if ($this->isSensitiveKey($keyString)) {
                $sanitized[$key] = self::MASK;

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeArray($value);

                continue;
            }

            if (is_string($value)) {
                $sanitized[$key] = $this->sanitizeString($value);

                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    public function sanitizeString(string $value): string
    {
        $value = preg_replace('/Bearer\s+[A-Za-z0-9\-_\.=]+/i', 'Bearer ' . self::MASK, $value) ?? $value;
        $value = preg_replace('/([?&](?:access_token|token|api_key|signature|sig|validation_token)=)[^&\s]+/i', '$1' . self::MASK, $value) ?? $value;

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = mb_strtolower($key);

        foreach (['validation_token', 'authorization', 'access_token', 'api_key', 'api_key_encrypted', 'token', 'preview_url'] as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
