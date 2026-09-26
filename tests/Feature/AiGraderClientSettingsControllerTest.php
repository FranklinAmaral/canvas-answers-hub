<?php

declare(strict_types=1);

use App\Models\AiGraderClientSetting;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

function aiGraderClientSettingsOrganization(array $overrides = []): Organization
{
    return Organization::query()->create(array_merge([
        'name' => 'GraderAI Client',
        'slug' => 'ai-grader-client-'.uniqid(),
        'timezone' => 'America/Sao_Paulo',
        'is_active' => true,
    ], $overrides));
}

function aiGraderClientSettingsPayload(array $overrides = []): array
{
    return array_merge([
        'enabled' => '1',
        'status' => AiGraderClientSetting::STATUS_ACTIVE,
        'plan_name' => 'Plano interno',
        'trial_quota_total' => 50,
        'purchased_quota_total' => 0,
        'quota_used' => 0,
        'quota_reserved' => 0,
        'notes' => 'Configuração de teste',
    ], $overrides);
}

it('allows an authenticated GraderAI user to access client settings index', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('ai-grader.client-settings.index'))
        ->assertOk()
        ->assertSeeText('Habilitação do GraderAI');
});

it('lists organizations without settings as not configured', function (): void {
    $user = User::factory()->create();

    aiGraderClientSettingsOrganization(['name' => 'Organização sem AI']);

    $this->actingAs($user)
        ->get(route('ai-grader.client-settings.index'))
        ->assertOk()
        ->assertSeeText('Organização sem AI')
        ->assertSeeText('Não configurado');
});

it('creates a trial client setting with default quota of fifty', function (): void {
    $user = User::factory()->create();
    $organization = aiGraderClientSettingsOrganization();

    $this->actingAs($user)
        ->put(route('ai-grader.client-settings.update', $organization), aiGraderClientSettingsPayload([
            'status' => AiGraderClientSetting::STATUS_TRIAL,
            'trial_quota_total' => '',
            'plan_name' => 'Trial assistido',
        ]))
        ->assertRedirect(route('ai-grader.client-settings.show', $organization));

    $this->assertDatabaseHas('ai_grader_client_settings', [
        'organization_id' => $organization->id,
        'enabled' => true,
        'status' => AiGraderClientSetting::STATUS_TRIAL,
        'trial_quota_total' => 50,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
});

it('updates purchased quota total for an existing setting', function (): void {
    $user = User::factory()->create();
    $organization = aiGraderClientSettingsOrganization();

    AiGraderClientSetting::query()->create([
        'organization_id' => $organization->id,
        'enabled' => true,
        'status' => AiGraderClientSetting::STATUS_ACTIVE,
        'trial_quota_total' => 50,
        'purchased_quota_total' => 10,
        'quota_used' => 0,
        'quota_reserved' => 0,
    ]);

    $this->actingAs($user)
        ->put(route('ai-grader.client-settings.update', $organization), aiGraderClientSettingsPayload([
            'purchased_quota_total' => 75,
        ]))
        ->assertRedirect(route('ai-grader.client-settings.show', $organization));

    $this->assertDatabaseHas('ai_grader_client_settings', [
        'organization_id' => $organization->id,
        'purchased_quota_total' => 75,
        'updated_by' => $user->id,
    ]);
});

it('does not include legacy provider policy columns in the baseline schema', function (): void {
    expect(Schema::hasColumns('ai_grader_client_settings', [
        'allow_customer_ai_provider',
        'allow_adminhub_managed_ai',
        'allow_private_ai_endpoint',
        'default_ai_provider_mode',
    ]))->toBeFalse();
});

it('rejects negative quota values', function (): void {
    $user = User::factory()->create();
    $organization = aiGraderClientSettingsOrganization();

    $this->actingAs($user)
        ->from(route('ai-grader.client-settings.edit', $organization))
        ->put(route('ai-grader.client-settings.update', $organization), aiGraderClientSettingsPayload([
            'purchased_quota_total' => -1,
        ]))
        ->assertRedirect(route('ai-grader.client-settings.edit', $organization))
        ->assertSessionHasErrors(['purchased_quota_total']);
});

it('shows available quota balance on the client settings detail page', function (): void {
    $user = User::factory()->create();
    $organization = aiGraderClientSettingsOrganization();

    AiGraderClientSetting::query()->create([
        'organization_id' => $organization->id,
        'enabled' => true,
        'status' => AiGraderClientSetting::STATUS_TRIAL,
        'trial_quota_total' => 50,
        'purchased_quota_total' => 10,
        'quota_used' => 7,
        'quota_reserved' => 3,
    ]);

    $this->actingAs($user)
        ->get(route('ai-grader.client-settings.show', $organization))
        ->assertOk()
        ->assertSeeText('Saldo disponível')
        ->assertSeeText('50');
});

it('shows quota_exceeded when available quota reaches zero', function (): void {
    $user = User::factory()->create();
    $organization = aiGraderClientSettingsOrganization();

    AiGraderClientSetting::query()->create([
        'organization_id' => $organization->id,
        'enabled' => true,
        'status' => AiGraderClientSetting::STATUS_ACTIVE,
        'trial_quota_total' => 50,
        'purchased_quota_total' => 0,
        'quota_used' => 50,
        'quota_reserved' => 0,
    ]);

    $this->actingAs($user)
        ->get(route('ai-grader.client-settings.show', $organization))
        ->assertOk()
        ->assertSeeText('quota_exceeded');
});

it('redirects guests away from client settings', function (): void {
    $this->get(route('ai-grader.client-settings.index'))
        ->assertRedirect(route('login'));
});
