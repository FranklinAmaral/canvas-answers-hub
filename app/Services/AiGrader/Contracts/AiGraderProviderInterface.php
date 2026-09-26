<?php

declare(strict_types=1);

namespace App\Services\AiGrader\Contracts;

use App\Models\AiGraderCorrectionItem;
use App\Services\AiGrader\Data\AiGraderPromptContext;
use App\Services\AiGrader\Data\AiGraderResult;

interface AiGraderProviderInterface
{
    public function grade(AiGraderCorrectionItem $item, AiGraderPromptContext $context): AiGraderResult;
}
