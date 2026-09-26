<?php

declare(strict_types=1);

use App\Models\AiGraderAiProvider;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function aiGraderProviderOrganization(): Organization
{
    config(['app.key' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']);

    return Organization::query()->create([
        'name' => 'Provider Org',
        'slug' => 'provider-org-' . uniqid(),
        'timezone' => 'America/Sao_Paulo',
        'is_active' => true,
    ]);
}

function aiGraderProviderPayload(Organization $organization, array $overrides = []): array
{
    return array_merge([
        'organization_id' => $organization->id,
        'name' => 'LM Studio Local',
        'provider_type' => AiGraderAiProvider::TYPE_LM_STUDIO,
        'base_url' => 'http://localhost:1234',
        'endpoint_path' => '/v1/chat/completions',
        'model' => 'local-model',
        'api_key' => 'secret-provider-token',
        'request_timeout_seconds' => 60,
        'max_tokens' => 800,
        'temperature' => '0.20',
        'enabled' => '1',
        'is_default' => '1',
    ], $overrides);
}

it('allows authenticated user to access providers index and menu link', function (): void {
    $user = User::factory()->create();
    aiGraderProviderOrganization();

    $this->actingAs($user)
        ->get(route('ai-grader.ai-providers.index'))
        ->assertOk()
        ->assertSee('Providers de IA')
        ->assertSee('GraderAI - Providers de IA')
        ->assertSee(route('ai-grader.ai-providers.create'));
});

it('creates lm studio provider', function (): void {
    $user = User::factory()->create();
    $organization = aiGraderProviderOrganization();

    $this->actingAs($user)
        ->post(route('ai-grader.ai-providers.store'), aiGraderProviderPayload($organization))
        ->assertRedirect();

    $provider = AiGraderAiProvider::query()->firstOrFail();

    expect($provider->provider_type)->toBe(AiGraderAiProvider::TYPE_LM_STUDIO)
        ->and($provider->endpointPath())->toBe('/v1/chat/completions')
        ->and($provider->api_key_encrypted)->toBe('secret-provider-token')
        ->and($provider->is_default)->toBeTrue();
});

it('rejects invalid endpoint configuration and an inactive default provider', function (array $overrides, string $field): void {
    $user = User::factory()->create();
    $organization = aiGraderProviderOrganization();

    $this->actingAs($user)
        ->from(route('ai-grader.ai-providers.create'))
        ->post(route('ai-grader.ai-providers.store'), aiGraderProviderPayload($organization, $overrides))
        ->assertRedirect(route('ai-grader.ai-providers.create'))
        ->assertSessionHasErrors([$field]);
})->with([
    'non-http endpoint' => [['base_url' => 'ftp://localhost:1234'], 'base_url'],
    'absolute endpoint path' => [['endpoint_path' => 'https://localhost/v1/chat/completions'], 'endpoint_path'],
    'inactive default' => [['enabled' => '0', 'is_default' => '1'], 'is_default'],
]);

it('shows provider index fields and actions', function (): void {
    $user = User::factory()->create();
    $organization = aiGraderProviderOrganization();
    $provider = AiGraderAiProvider::query()->create([
        'organization_id' => $organization->id,
        'name' => 'LM Studio Index',
        'provider_type' => AiGraderAiProvider::TYPE_LM_STUDIO,
        'base_url' => 'http://localhost:1234',
        'model' => 'index-model',
        'api_key_encrypted' => 'index-secret',
        'enabled' => true,
        'is_default' => true,
        'settings' => ['endpoint_path' => '/v1/chat/completions'],
    ]);

    $this->actingAs($user)
        ->get(route('ai-grader.ai-providers.index'))
        ->assertOk()
        ->assertSee('LM Studio Index')
        ->assertSee('http://localhost:1234')
        ->assertSee('/v1/chat/completions')
        ->assertSee('index-model')
        ->assertSee('Testar')
        ->assertSee('Excluir')
        ->assertSee(route('ai-grader.ai-providers.test', $provider))
        ->assertSee(route('ai-grader.ai-providers.destroy', $provider));
});

it('edits provider keeping token when api key field is empty', function (): void {
    $user = User::factory()->create();
    $organization = aiGraderProviderOrganization();
    $provider = AiGraderAiProvider::query()->create([
        'organization_id' => $organization->id,
        'name' => 'LM Studio',
        'provider_type' => AiGraderAiProvider::TYPE_LM_STUDIO,
        'base_url' => 'http://localhost:1234',
        'model' => 'old-model',
        'api_key_encrypted' => 'original-token',
        'enabled' => true,
        'is_default' => true,
        'settings' => [
            'endpoint_path' => '/v1/chat/completions',
            'private_setting' => 'hidden-settings-secret',
        ],
    ]);

    $response = $this->actingAs($user)
        ->get(route('ai-grader.ai-providers.edit', $provider));

    $response->assertOk()
        ->assertSee('Bearer Token configurado: sim')
        ->assertDontSee('original-token')
        ->assertDontSee('hidden-settings-secret');

    $this->actingAs($user)
        ->put(route('ai-grader.ai-providers.update', $provider), aiGraderProviderPayload($organization, [
            'name' => 'LM Studio Updated',
            'api_key' => '',
            'model' => 'new-model',
        ]))
        ->assertRedirect();

    $provider->refresh();

    expect($provider->name)->toBe('LM Studio Updated')
        ->and($provider->model)->toBe('new-model')
        ->and($provider->api_key_encrypted)->toBe('original-token')
        ->and($provider->settings['private_setting'])->toBe('hidden-settings-secret');
});

it('does not expose provider token on show page', function (): void {
    $user = User::factory()->create();
    $organization = aiGraderProviderOrganization();
    $provider = AiGraderAiProvider::query()->create([
        'organization_id' => $organization->id,
        'name' => 'LM Studio Show',
        'provider_type' => AiGraderAiProvider::TYPE_LM_STUDIO,
        'base_url' => 'http://localhost:1234',
        'model' => 'show-model',
        'api_key_encrypted' => 'very-secret-token',
        'enabled' => true,
        'is_default' => true,
        'settings' => ['endpoint_path' => '/v1/chat/completions'],
    ]);

    $this->actingAs($user)
        ->get(route('ai-grader.ai-providers.show', $provider))
        ->assertOk()
        ->assertSee('Bearer Token configurado')
        ->assertSee('sim')
        ->assertDontSee('very-secret-token');
});

it('process pending command shows friendly error when provider id does not exist', function (): void {
    $this->artisan('ai-grader:process-pending --provider-id=999')
        ->expectsOutputToContain('Provider ID 999')
        ->assertExitCode(Command::FAILURE);
});

it('keeps only one default provider per organization', function (): void {
    $user = User::factory()->create();
    $organization = aiGraderProviderOrganization();
    $first = AiGraderAiProvider::query()->create([
        'organization_id' => $organization->id,
        'name' => 'First',
        'provider_type' => AiGraderAiProvider::TYPE_MOCK,
        'enabled' => true,
        'is_default' => true,
    ]);
    $second = AiGraderAiProvider::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Second',
        'provider_type' => AiGraderAiProvider::TYPE_MOCK,
        'enabled' => true,
        'is_default' => false,
    ]);

    $this->actingAs($user)
        ->put(route('ai-grader.ai-providers.update', $second), aiGraderProviderPayload($organization, [
            'name' => 'Second',
            'provider_type' => AiGraderAiProvider::TYPE_MOCK,
            'base_url' => null,
            'endpoint_path' => null,
            'model' => null,
            'api_key' => '',
            'is_default' => '1',
        ]))
        ->assertRedirect();

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($second->fresh()->is_default)->toBeTrue();
});

it('test connection returns success with http fake', function (): void {
    $user = User::factory()->create();
    $organization = aiGraderProviderOrganization();
    $provider = AiGraderAiProvider::query()->create([
        'organization_id' => $organization->id,
        'name' => 'LM Studio',
        'provider_type' => AiGraderAiProvider::TYPE_LM_STUDIO,
        'base_url' => 'http://localhost:1234',
        'model' => 'local-model',
        'api_key_encrypted' => 'connection-token',
        'request_timeout_seconds' => 30,
        'enabled' => true,
        'is_default' => true,
        'settings' => ['endpoint_path' => '/v1/chat/completions'],
    ]);

    Http::fake([
        'http://localhost:1234/v1/chat/completions' => Http::response([
            'choices' => [
                ['message' => ['content' => '{"ok":true}']],
            ],
        ]),
    ]);

    $this->actingAs($user)
        ->post(route('ai-grader.ai-providers.test', $provider))
        ->assertRedirect()
        ->assertSessionHas('success', 'Conexao com LM Studio realizada com sucesso.');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer connection-token'));
});
