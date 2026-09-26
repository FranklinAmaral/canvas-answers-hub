<?php

declare(strict_types=1);

use App\Models\AiGraderAiProvider;
use App\Models\AiGraderBlueprintConfig;
use App\Models\AiGraderClientSetting;
use App\Models\AiGraderCorrectionItem;
use App\Models\CanvasEnvironment;
use App\Models\Organization;
use App\Services\AiGrader\AiGraderProviderResolver;
use App\Services\AiGrader\Providers\LmStudioAiGraderProvider;
use App\Services\AiGrader\Providers\MockAiGraderProvider;
use Illuminate\Console\Command;

function providerPolicyOrganization(string $suffix): Organization
{
    return Organization::query()->create([
        'name' => 'Provider Policy ' . $suffix,
        'slug' => 'provider-policy-' . $suffix . '-' . uniqid(),
        'timezone' => 'America/Sao_Paulo',
        'is_active' => true,
    ]);
}

function providerPolicyEnvironment(Organization $organization): CanvasEnvironment
{
    config(['app.key' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']);

    return CanvasEnvironment::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Provider Policy Canvas',
        'slug' => 'provider-policy-canvas-' . uniqid(),
        'environment_type' => 'test',
        'base_url' => 'https://canvas.test',
        'api_token' => 'secret-token',
        'is_active' => true,
        'is_default' => true,
    ]);
}

function providerPolicyProvider(Organization $organization, array $overrides = []): AiGraderAiProvider
{
    return AiGraderAiProvider::query()->create(array_merge([
        'organization_id' => $organization->id,
        'name' => 'Policy LM Studio',
        'provider_type' => AiGraderAiProvider::TYPE_LM_STUDIO,
        'base_url' => 'http://localhost:1234',
        'model' => 'policy-model',
        'enabled' => true,
        'is_default' => false,
        'settings' => ['endpoint_path' => '/v1/chat/completions'],
    ], $overrides));
}

function providerPolicyItem(Organization $organization, CanvasEnvironment $environment, ?AiGraderAiProvider $provider = null): AiGraderCorrectionItem
{
    $blueprint = null;

    if ($provider !== null) {
        $blueprint = AiGraderBlueprintConfig::query()->create([
            'organization_id' => $organization->id,
            'canvas_environment_id' => $environment->id,
            'blueprint_course_id' => 'provider-policy-' . uniqid(),
            'enabled' => true,
            'publication_mode' => AiGraderBlueprintConfig::PUBLICATION_TEACHER_APPROVAL,
            'trigger_mode' => AiGraderBlueprintConfig::TRIGGER_MANUAL,
            'default_feedback_language' => 'pt_BR',
            'ai_provider_id' => $provider->id,
        ]);
    }

    $unique = uniqid();

    return AiGraderCorrectionItem::query()->create([
        'organization_id' => $organization->id,
        'canvas_environment_id' => $environment->id,
        'ai_grader_blueprint_config_id' => $blueprint?->id,
        'child_course_id' => 'child-' . $unique,
        'canvas_quiz_id' => 'quiz-' . $unique,
        'canvas_assignment_id' => 'assignment-' . $unique,
        'canvas_quiz_submission_id' => 'submission-' . $unique,
        'canvas_user_id' => 'user-' . $unique,
        'attempt' => 1,
        'canvas_question_id' => 'question-' . $unique,
        'canvas_points_possible' => 10,
        'answer_text' => 'Resposta válida.',
        'ai_grading_instructions_snapshot' => 'Avalie a resposta.',
        'status' => AiGraderCorrectionItem::STATUS_PENDING,
    ]);
}

it('resolves the active provider explicitly configured on the blueprint', function (): void {
    $organization = providerPolicyOrganization('explicit');
    $environment = providerPolicyEnvironment($organization);
    $provider = providerPolicyProvider($organization);
    $item = providerPolicyItem($organization, $environment, $provider);

    $resolved = app(AiGraderProviderResolver::class)->providerForItem($item);

    expect($resolved->is($provider))->toBeTrue();
});

it('uses the organization default when the blueprint provider is inactive', function (): void {
    $organization = providerPolicyOrganization('inactive');
    $environment = providerPolicyEnvironment($organization);
    $inactive = providerPolicyProvider($organization, ['name' => 'Inactive', 'enabled' => false]);
    $default = providerPolicyProvider($organization, ['name' => 'Default', 'is_default' => true]);
    $item = providerPolicyItem($organization, $environment, $inactive);

    $resolved = app(AiGraderProviderResolver::class)->providerForItem($item);

    expect($resolved->is($default))->toBeTrue();
});

it('never resolves an inactive provider when no valid default exists', function (): void {
    $organization = providerPolicyOrganization('inactive-only');
    $environment = providerPolicyEnvironment($organization);
    $inactive = providerPolicyProvider($organization, ['enabled' => false]);
    $item = providerPolicyItem($organization, $environment, $inactive);

    expect(fn () => app(AiGraderProviderResolver::class)->resolveForItem($item))
        ->toThrow(RuntimeException::class, 'No enabled AI provider is configured for this organization');
});

it('rejects a blueprint provider that belongs to another organization', function (): void {
    $organization = providerPolicyOrganization('owner');
    $otherOrganization = providerPolicyOrganization('other');
    $environment = providerPolicyEnvironment($organization);
    $provider = providerPolicyProvider($otherOrganization);
    $item = providerPolicyItem($organization, $environment, $provider);

    expect(fn () => app(AiGraderProviderResolver::class)->providerForItem($item))
        ->toThrow(RuntimeException::class, 'does not belong to the correction organization');
});

it('fails explicitly when no provider can be resolved', function (): void {
    $organization = providerPolicyOrganization('missing');
    $environment = providerPolicyEnvironment($organization);
    $item = providerPolicyItem($organization, $environment);

    expect(fn () => app(AiGraderProviderResolver::class)->resolveForItem($item))
        ->toThrow(RuntimeException::class, 'No enabled AI provider is configured for this organization');
});

it('supports explicit LM Studio and Mock provider records', function (): void {
    $organization = providerPolicyOrganization('types');
    $lmStudio = providerPolicyProvider($organization);
    $mock = providerPolicyProvider($organization, [
        'name' => 'Policy Mock',
        'provider_type' => AiGraderAiProvider::TYPE_MOCK,
        'base_url' => null,
        'model' => null,
        'settings' => null,
    ]);
    $resolver = app(AiGraderProviderResolver::class);

    expect($resolver->resolve($lmStudio))->toBeInstanceOf(LmStudioAiGraderProvider::class)
        ->and($resolver->resolve($mock))->toBeInstanceOf(MockAiGraderProvider::class);
});

it('rejects incomplete LM Studio configuration', function (): void {
    $organization = providerPolicyOrganization('incomplete');
    $provider = providerPolicyProvider($organization, ['model' => null]);

    expect(fn () => app(AiGraderProviderResolver::class)->resolve($provider))
        ->toThrow(RuntimeException::class, 'incomplete or invalid configuration');
});

it('rejects legacy adminhub managed records without modifying them', function (): void {
    $organization = providerPolicyOrganization('legacy');
    $provider = providerPolicyProvider($organization, [
        'provider_type' => 'adminhub_managed',
        'base_url' => null,
        'model' => null,
        'is_default' => true,
    ]);

    expect(fn () => app(AiGraderProviderResolver::class)->resolve($provider))
        ->toThrow(RuntimeException::class, 'is not supported');

    expect($provider->fresh()->provider_type)->toBe('adminhub_managed');
});

it('rejects an override provider from another organization', function (): void {
    $organization = providerPolicyOrganization('override-owner');
    $otherOrganization = providerPolicyOrganization('override-other');
    $environment = providerPolicyEnvironment($organization);
    $item = providerPolicyItem($organization, $environment);
    $provider = providerPolicyProvider($otherOrganization);

    expect(fn () => app(AiGraderProviderResolver::class)->providerForItem($item, $provider))
        ->toThrow(RuntimeException::class, 'does not belong to the correction organization');
});

it('processes a valid explicit provider override from the CLI', function (): void {
    $organization = providerPolicyOrganization('cli-valid');
    $environment = providerPolicyEnvironment($organization);
    $provider = providerPolicyProvider($organization, [
        'name' => 'CLI Mock',
        'provider_type' => AiGraderAiProvider::TYPE_MOCK,
        'base_url' => null,
        'model' => null,
        'settings' => null,
    ]);
    AiGraderClientSetting::query()->create([
        'organization_id' => $organization->id,
        'enabled' => true,
        'status' => AiGraderClientSetting::STATUS_ACTIVE,
        'trial_quota_total' => 10,
        'purchased_quota_total' => 0,
        'quota_used' => 0,
        'quota_reserved' => 1,
    ]);
    $item = providerPolicyItem($organization, $environment);

    $this->artisan(sprintf(
        'ai-grader:process-pending --item-id=%d --provider-id=%d',
        $item->id,
        $provider->id,
    ))->assertExitCode(Command::SUCCESS);

    expect($item->fresh()->status)->toBe(AiGraderCorrectionItem::STATUS_PENDING_TEACHER_APPROVAL)
        ->and(data_get($item->fresh()->ai_raw_response, 'ai_provider_id'))->toBe($provider->id);
});

it('fails safely for an invalid cross-organization CLI override', function (): void {
    $organization = providerPolicyOrganization('cli-owner');
    $otherOrganization = providerPolicyOrganization('cli-other');
    $environment = providerPolicyEnvironment($organization);
    $provider = providerPolicyProvider($otherOrganization, [
        'name' => 'Other CLI Mock',
        'provider_type' => AiGraderAiProvider::TYPE_MOCK,
        'base_url' => null,
        'model' => null,
        'settings' => null,
    ]);
    AiGraderClientSetting::query()->create([
        'organization_id' => $organization->id,
        'enabled' => true,
        'status' => AiGraderClientSetting::STATUS_ACTIVE,
        'trial_quota_total' => 10,
        'purchased_quota_total' => 0,
        'quota_used' => 0,
        'quota_reserved' => 1,
    ]);
    $item = providerPolicyItem($organization, $environment);

    $this->artisan(sprintf(
        'ai-grader:process-pending --item-id=%d --provider-id=%d',
        $item->id,
        $provider->id,
    ))->assertExitCode(Command::FAILURE);

    expect($item->fresh()->status)->toBe(AiGraderCorrectionItem::STATUS_FAILED)
        ->and($item->fresh()->failure_reason)->toContain('does not belong to the correction organization');
});

it('fails when more than one enabled default provider exists', function (): void {
    $organization = providerPolicyOrganization('ambiguous');
    $environment = providerPolicyEnvironment($organization);
    providerPolicyProvider($organization, ['name' => 'Default One', 'is_default' => true]);
    providerPolicyProvider($organization, ['name' => 'Default Two', 'is_default' => true]);
    $item = providerPolicyItem($organization, $environment);

    expect(fn () => app(AiGraderProviderResolver::class)->providerForItem($item))
        ->toThrow(RuntimeException::class, 'Multiple enabled default AI providers');
});
