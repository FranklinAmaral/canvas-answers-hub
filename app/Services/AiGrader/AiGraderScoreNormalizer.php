<?php

declare(strict_types=1);

namespace App\Services\AiGrader;

use InvalidArgumentException;

class AiGraderScoreNormalizer
{
    public function normalize(float $score, float $pointsPossible): float
    {
        if ($pointsPossible <= 0) {
            throw new InvalidArgumentException('pointsPossible must be greater than zero.');
        }

        $score = max(0.0, $score);
        $score = min($score, $pointsPossible);

        return round($score, 2);
    }
}
