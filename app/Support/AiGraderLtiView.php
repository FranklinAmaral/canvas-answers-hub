<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

class AiGraderLtiView
{
    /**
     * @param CarbonInterface|string|null $value
     */
    public static function formatDateTime(mixed $value, string $fallback = '-'): string
    {
        if (! $value instanceof CarbonInterface) {
            return $fallback;
        }

        $localized = $value->copy()->timezone((string) config('app.timezone', 'UTC'));

        return $localized->format(self::dateTimeFormat());
    }

    public static function dateTimeFormat(): string
    {
        return App::currentLocale() === 'en'
            ? 'm/d/Y h:i A'
            : 'd/m/Y H:i';
    }

    public static function normalizeRubricLabel(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = Str::of(Str::ascii($value))
            ->lower()
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->value();

        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            'nao observada', 'nao observado' => 'nao observado',
            'precisa de melhorar', 'precisa de melhoria', 'precisa melhorar' => 'precisa melhorar',
            default => $normalized,
        };
    }

    /**
     * @param array<int, array<string, mixed>> $ratings
     * @return array<string, mixed>|null
     */
    public static function findMatchingRubricRating(array $ratings, ?string $label = null, ?float $percentage = null): ?array
    {
        $normalizedLabel = self::normalizeRubricLabel($label);

        if ($normalizedLabel !== null) {
            foreach ($ratings as $rating) {
                if (! is_array($rating)) {
                    continue;
                }

                $candidates = [
                    self::normalizeRubricLabel(self::stringOrNull($rating['description'] ?? null)),
                    self::normalizeRubricLabel(self::stringOrNull($rating['long_description'] ?? null)),
                    self::normalizeRubricLabel(self::stringOrNull($rating['id'] ?? null)),
                ];

                if (in_array($normalizedLabel, array_filter($candidates), true)) {
                    return $rating;
                }
            }
        }

        if ($percentage === null) {
            return null;
        }

        $closest = null;
        $smallestDifference = null;

        foreach ($ratings as $rating) {
            if (! is_array($rating) || ! is_numeric($rating['percentage'] ?? null)) {
                continue;
            }

            $ratingPercentage = (float) $rating['percentage'];
            $difference = abs($ratingPercentage - $percentage);

            if ($difference < 0.01) {
                return $rating;
            }

            if (
                $smallestDifference === null
                || $difference < $smallestDifference
                || (
                    abs($difference - $smallestDifference) < 0.01
                    && $closest !== null
                    && $ratingPercentage > (float) ($closest['percentage'] ?? 0)
                )
            ) {
                $smallestDifference = $difference;
                $closest = $rating;
            }
        }

        return $closest;
    }

    public static function rubricSelectionChanged(?string $selectedLabel, ?string $aiLabel, ?float $selectedPercentage, ?float $aiPercentage): bool
    {
        $normalizedSelected = self::normalizeRubricLabel($selectedLabel);
        $normalizedAi = self::normalizeRubricLabel($aiLabel);

        if ($normalizedSelected !== null && $normalizedAi !== null) {
            return $normalizedSelected !== $normalizedAi;
        }

        if ($selectedPercentage !== null && $aiPercentage !== null) {
            return abs($selectedPercentage - $aiPercentage) >= 0.01;
        }

        return false;
    }

    public static function criterionProportionalScore(?float $percentage, ?float $pointsPossible): ?float
    {
        if ($percentage === null || $pointsPossible === null) {
            return null;
        }

        return round(($percentage / 100) * $pointsPossible, 2);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
