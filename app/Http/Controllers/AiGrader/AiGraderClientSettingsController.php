<?php

declare(strict_types=1);

namespace App\Http\Controllers\AiGrader;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiGrader\UpdateAiGraderClientSettingRequest;
use App\Models\AiGraderBlueprintConfig;
use App\Models\AiGraderClientSetting;
use App\Models\AiGraderCorrectionItem;
use App\Models\AiGraderQuestionConfig;
use App\Models\AiGraderQuizConfig;
use App\Models\Organization;
use App\Services\AiGrader\AiGraderQuotaService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AiGraderClientSettingsController extends Controller
{
    public function __construct(
        private readonly AiGraderQuotaService $quotaService,
    ) {
    }

    public function index(Request $request): View
    {
        $organizations = Organization::query()
            ->with('aiGraderClientSetting')
            ->orderBy('name')
            ->paginate(15);

        return view('ai-grader.client-settings.index', [
            'organizations' => $organizations,
            'summaries' => $organizations->getCollection()
                ->mapWithKeys(fn (Organization $organization): array => [
                    $organization->id => $this->summaryFor($organization),
                ]),
        ]);
    }

    public function show(Organization $organization): View
    {
        $organization->load('aiGraderClientSetting');

        return view('ai-grader.client-settings.show', [
            'organization' => $organization,
            'setting' => $organization->aiGraderClientSetting,
            'summary' => $this->summaryFor($organization),
            'counters' => $this->countersFor($organization),
        ]);
    }

    public function edit(Organization $organization): View
    {
        $organization->load('aiGraderClientSetting');

        return view('ai-grader.client-settings.edit', [
            'organization' => $organization,
            'setting' => $organization->aiGraderClientSetting,
            'statuses' => $this->statuses(),
        ]);
    }

    public function update(UpdateAiGraderClientSettingRequest $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validated();
        $setting = $organization->aiGraderClientSetting;

        $data = [
            'organization_id' => $organization->id,
            'enabled' => (bool) ($validated['enabled'] ?? false),
            'status' => $validated['status'],
            'plan_name' => $validated['plan_name'] ?? null,
            'trial_quota_total' => (int) ($validated['trial_quota_total'] ?? 0),
            'purchased_quota_total' => (int) $validated['purchased_quota_total'],
            'quota_used' => (int) $validated['quota_used'],
            'quota_reserved' => (int) $validated['quota_reserved'],
            'quota_reset_at' => $validated['quota_reset_at'] ?? null,
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'updated_by' => $request->user()?->id,
        ];

        if (! $data['enabled'] && $data['status'] !== AiGraderClientSetting::STATUS_INACTIVE) {
            $data['status'] = AiGraderClientSetting::STATUS_INACTIVE;
        }

        if ($setting) {
            $setting->update($data);
        } else {
            $data['created_by'] = $request->user()?->id;
            $setting = AiGraderClientSetting::query()->create($data);
        }

        return redirect()
            ->route('ai-grader.client-settings.show', $organization)
            ->with('success', 'Configuração do GraderAI atualizada com sucesso.');
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryFor(Organization $organization): array
    {
        $setting = $organization->aiGraderClientSetting;
        $totalQuota = $setting ? $setting->trial_quota_total + $setting->purchased_quota_total : 0;
        $available = $this->quotaService->availableForOrganization($organization);
        $usedPercent = $totalQuota > 0 && $setting
            ? round(($setting->quota_used / $totalQuota) * 100, 1)
            : 0.0;

        return [
            'total_quota' => $totalQuota,
            'available' => $available,
            'used_percent' => $usedPercent,
            'display_status' => $this->displayStatus($setting, $available, $totalQuota),
            'state_message' => $this->stateMessage($setting, $available, $totalQuota),
            'state_tone' => $this->stateTone($setting, $available, $totalQuota),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function countersFor(Organization $organization): array
    {
        return [
            'blueprints' => AiGraderBlueprintConfig::query()->where('organization_id', $organization->id)->count(),
            'quizzes' => AiGraderQuizConfig::query()
                ->whereHas('blueprintConfig', fn ($query) => $query->where('organization_id', $organization->id))
                ->count(),
            'questions' => AiGraderQuestionConfig::query()
                ->whereHas('quizConfig.blueprintConfig', fn ($query) => $query->where('organization_id', $organization->id))
                ->count(),
            'correction_items' => AiGraderCorrectionItem::query()->where('organization_id', $organization->id)->count(),
            'pending_quota' => AiGraderCorrectionItem::query()
                ->where('organization_id', $organization->id)
                ->where('status', AiGraderCorrectionItem::STATUS_PENDING_QUOTA)
                ->count(),
            'published_to_canvas' => AiGraderCorrectionItem::query()
                ->where('organization_id', $organization->id)
                ->where('status', AiGraderCorrectionItem::STATUS_PUBLISHED_TO_CANVAS)
                ->count(),
        ];
    }

    private function stateMessage(?AiGraderClientSetting $setting, int $available, int $totalQuota): string
    {
        if (! $setting || ! $setting->enabled || $setting->status === AiGraderClientSetting::STATUS_INACTIVE) {
            return 'Módulo inativo para esta organização.';
        }

        if ($setting->status === AiGraderClientSetting::STATUS_SUSPENDED) {
            return 'Módulo suspenso.';
        }

        if ($setting->status === AiGraderClientSetting::STATUS_QUOTA_EXCEEDED) {
            return 'Cota esgotada. Novas respostas discursivas ficarão pendentes por limite.';
        }

        if ($available <= 0 && $totalQuota > 0) {
            return 'Cota esgotada. Novas respostas discursivas ficarão pendentes por limite.';
        }

        if ($setting->status === AiGraderClientSetting::STATUS_TRIAL) {
            return 'Trial ativo.';
        }

        return 'Módulo ativo.';
    }

    private function stateTone(?AiGraderClientSetting $setting, int $available, int $totalQuota): string
    {
        if (! $setting || ! $setting->enabled || $setting->status === AiGraderClientSetting::STATUS_INACTIVE) {
            return 'secondary';
        }

        if (
            $setting->status === AiGraderClientSetting::STATUS_SUSPENDED
            || $setting->status === AiGraderClientSetting::STATUS_QUOTA_EXCEEDED
            || ($available <= 0 && $totalQuota > 0)
        ) {
            return 'danger';
        }

        if ($setting->status === AiGraderClientSetting::STATUS_TRIAL) {
            return 'info';
        }

        return 'success';
    }

    private function displayStatus(?AiGraderClientSetting $setting, int $available, int $totalQuota): string
    {
        if (! $setting) {
            return 'Não configurado';
        }

        if ($setting->enabled && $available <= 0 && $totalQuota > 0) {
            return AiGraderClientSetting::STATUS_QUOTA_EXCEEDED;
        }

        return $setting->status;
    }

    /**
     * @return array<string, string>
     */
    private function statuses(): array
    {
        return [
            AiGraderClientSetting::STATUS_INACTIVE => 'inactive',
            AiGraderClientSetting::STATUS_TRIAL => 'trial',
            AiGraderClientSetting::STATUS_ACTIVE => 'active',
            AiGraderClientSetting::STATUS_SUSPENDED => 'suspended',
            AiGraderClientSetting::STATUS_QUOTA_EXCEEDED => 'quota_exceeded',
        ];
    }

}
