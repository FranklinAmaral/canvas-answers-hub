<?php

declare(strict_types=1);

namespace App\Services\AiGrader;

use App\Models\AiGraderClientSetting;
use App\Models\AiGraderCorrectionItem;
use App\Models\AiGraderUsageLog;
use App\Models\Organization;
use RuntimeException;

class AiGraderQuotaService
{
    public function availableForOrganization(Organization $organization): int
    {
        $setting = $this->settingFor($organization);

        if (! $setting) {
            return 0;
        }

        $total = $setting->trial_quota_total + $setting->purchased_quota_total;

        return max(0, $total - $setting->quota_used - $setting->quota_reserved);
    }

    public function canConsume(Organization $organization, int $quantity = 1): bool
    {
        if ($quantity < 1) {
            return true;
        }

        return $this->availableForOrganization($organization) >= $quantity;
    }

    public function reserve(AiGraderCorrectionItem $item): void
    {
        $setting = $this->settingForItem($item);

        if ($this->availableForOrganization($setting->organization) < 1) {
            throw new RuntimeException('AI Grader quota is not available for this organization.');
        }

        $setting->increment('quota_reserved');
        $this->logUsage($item, AiGraderUsageLog::TYPE_CORRECTION_RESERVED);
    }

    /**
     * @param array<string, mixed> $usageAttributes
     */
    public function consume(AiGraderCorrectionItem $item, array $usageAttributes = []): void
    {
        $setting = $this->settingForItem($item);

        if ($setting->quota_reserved > 0) {
            $setting->decrement('quota_reserved');
        }

        $setting->increment('quota_used');
        $this->logUsage($item, AiGraderUsageLog::TYPE_CORRECTION_CONSUMED, $usageAttributes);
    }

    /**
     * @param array<string, mixed> $usageAttributes
     */
    public function release(AiGraderCorrectionItem $item, array $usageAttributes = []): void
    {
        $setting = $this->settingForItem($item);

        if ($setting->quota_reserved > 0) {
            $setting->decrement('quota_reserved');
        }

        $this->logUsage($item, AiGraderUsageLog::TYPE_CORRECTION_RELEASED, $usageAttributes);
    }

    private function settingFor(Organization $organization): ?AiGraderClientSetting
    {
        return AiGraderClientSetting::query()
            ->where('organization_id', $organization->id)
            ->first();
    }

    private function settingForItem(AiGraderCorrectionItem $item): AiGraderClientSetting
    {
        $item->loadMissing('organization');

        $setting = $item->organization ? $this->settingFor($item->organization) : null;

        if (! $setting) {
            throw new RuntimeException('AI Grader client settings were not found for this organization.');
        }

        return $setting;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function logUsage(AiGraderCorrectionItem $item, string $usageType, array $attributes = []): void
    {
        if (! $item->exists) {
            return;
        }

        AiGraderUsageLog::query()->create(array_merge([
            'organization_id' => $item->organization_id,
            'canvas_environment_id' => $item->canvas_environment_id,
            'ai_grader_correction_item_id' => $item->id,
            'ai_grader_batch_id' => $item->ai_grader_batch_id,
            'usage_type' => $usageType,
            'quantity' => 1,
            'status' => 'recorded',
        ], $attributes, [
            'usage_type' => $usageType,
        ]));
    }
}
