<?php

declare(strict_types=1);

use App\Models\CanvasEnvironment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('stores Canvas environment token encrypted exactly once', function (): void {
    $user = User::factory()->create();
    $organization = canvasEnvironmentTestOrganization();

    $this->actingAs($user)
        ->post(route('canvas-environments.store'), [
            'organization_id' => $organization->id,
            'name' => 'Canvas Test',
            'slug' => 'canvas-test',
            'environment_type' => 'test',
            'base_url' => 'https://canvas.test',
            'api_token' => 'secret-token',
            'is_active' => '1',
            'is_default' => '1',
        ])
        ->assertRedirect(route('canvas-environments.index'))
        ->assertSessionHas('success', 'Ambiente Canvas criado com sucesso.');

    $environment = CanvasEnvironment::query()->firstOrFail();

    expect($environment->api_token)->toBe('secret-token')
        ->and($environment->getRawOriginal('api_token'))->not->toBe('secret-token');
});

it('persists an explicit active LTI registration for a Canvas environment', function (): void {
    $user = User::factory()->create();
    $organization = canvasEnvironmentTestOrganization();

    $this->actingAs($user)
        ->post(route('canvas-environments.store'), [
            'organization_id' => $organization->id,
            'name' => 'Canvas LTI',
            'slug' => 'canvas-lti',
            'environment_type' => 'production',
            'base_url' => 'https://canvas.example.test',
            'api_token' => 'secret-token',
            'is_active' => '1',
            'lti_enabled' => '1',
            'lti_issuer' => 'https://canvas.instructure.com',
            'lti_client_id' => 'client-123',
            'lti_deployment_id' => 'deployment-123',
            'lti_authorization_endpoint' => 'https://canvas.example.test/api/lti/authorize_redirect',
            'lti_jwks_uri' => 'https://canvas.example.test/api/lti/security/jwks',
        ])
        ->assertRedirect(route('canvas-environments.index'));

    $this->assertDatabaseHas('canvas_environments', [
        'organization_id' => $organization->id,
        'lti_enabled' => true,
        'lti_issuer' => 'https://canvas.instructure.com',
        'lti_client_id' => 'client-123',
        'lti_deployment_id' => 'deployment-123',
        'lti_authorization_endpoint' => 'https://canvas.example.test/api/lti/authorize_redirect',
        'lti_jwks_uri' => 'https://canvas.example.test/api/lti/security/jwks',
    ]);
});

it('rejects an LTI endpoint containing query parameters', function (): void {
    $user = User::factory()->create();
    $organization = canvasEnvironmentTestOrganization();

    $this->actingAs($user)
        ->post(route('canvas-environments.store'), [
            'organization_id' => $organization->id,
            'name' => 'Canvas LTI',
            'slug' => 'canvas-lti',
            'environment_type' => 'production',
            'base_url' => 'https://canvas.example.test',
            'api_token' => 'secret-token',
            'is_active' => '1',
            'lti_enabled' => '1',
            'lti_issuer' => 'https://canvas.instructure.com',
            'lti_client_id' => 'client-123',
            'lti_deployment_id' => 'deployment-123',
            'lti_authorization_endpoint' => 'https://canvas.example.test/api/lti/authorize_redirect?client=attacker',
            'lti_jwks_uri' => 'https://canvas.example.test/api/lti/security/jwks',
        ])
        ->assertSessionHasErrors('lti_authorization_endpoint');

    expect(CanvasEnvironment::query()->count())->toBe(0);
});

it('keeps the current token when update receives blank api token', function (): void {
    $user = User::factory()->create();
    $organization = canvasEnvironmentTestOrganization();
    $environment = canvasEnvironmentTestEnvironment($organization, [
        'api_token' => 'original-token',
    ]);

    $rawTokenBefore = $environment->getRawOriginal('api_token');

    $this->actingAs($user)
        ->put(route('canvas-environments.update', $environment), [
            'organization_id' => $organization->id,
            'name' => 'Canvas Updated',
            'slug' => 'canvas-updated',
            'environment_type' => 'test',
            'base_url' => 'https://canvas-updated.test',
            'api_token' => '',
            'is_active' => '1',
            'is_default' => '1',
        ])
        ->assertRedirect(route('canvas-environments.index'))
        ->assertSessionHas('success', 'Ambiente Canvas atualizado com sucesso.');

    $environment->refresh();

    expect($environment->api_token)->toBe('original-token')
        ->and($environment->getRawOriginal('api_token'))->toBe($rawTokenBefore)
        ->and($environment->base_url)->toBe('https://canvas-updated.test');
});

it('replaces the token when update receives a new api token', function (): void {
    $user = User::factory()->create();
    $organization = canvasEnvironmentTestOrganization();
    $environment = canvasEnvironmentTestEnvironment($organization, [
        'api_token' => 'original-token',
    ]);

    $rawTokenBefore = $environment->getRawOriginal('api_token');

    $this->actingAs($user)
        ->put(route('canvas-environments.update', $environment), [
            'organization_id' => $organization->id,
            'name' => 'Canvas Updated',
            'slug' => 'canvas-updated',
            'environment_type' => 'test',
            'base_url' => 'https://canvas-updated.test',
            'api_token' => 'replacement-token',
            'is_active' => '1',
            'is_default' => '0',
        ])
        ->assertRedirect(route('canvas-environments.index'))
        ->assertSessionHas('success', 'Ambiente Canvas atualizado com sucesso.');

    $environment->refresh();

    expect($environment->api_token)->toBe('replacement-token')
        ->and($environment->getRawOriginal('api_token'))->not->toBe($rawTokenBefore)
        ->and($environment->getRawOriginal('api_token'))->not->toBe('replacement-token');
});

it('replaces an invalid stored token when update receives a new api token', function (): void {
    $user = User::factory()->create();
    $organization = canvasEnvironmentTestOrganization();
    $environment = canvasEnvironmentTestEnvironment($organization, [
        'api_token' => 'original-token',
    ]);

    DB::table('canvas_environments')
        ->where('id', $environment->id)
        ->update(['api_token' => 'not-encrypted']);

    $this->actingAs($user)
        ->put(route('canvas-environments.update', $environment), [
            'organization_id' => $organization->id,
            'name' => 'Canvas Updated',
            'slug' => 'canvas-updated',
            'environment_type' => 'test',
            'base_url' => 'https://canvas-updated.test',
            'api_token' => 'replacement-token',
            'is_active' => '1',
            'is_default' => '0',
        ])
        ->assertRedirect(route('canvas-environments.index'))
        ->assertSessionHas('success', 'Ambiente Canvas atualizado com sucesso.');

    $environment = CanvasEnvironment::query()->findOrFail($environment->id);

    expect($environment->api_token)->toBe('replacement-token')
        ->and($environment->getRawOriginal('api_token'))->not->toBe('replacement-token')
        ->and($environment->base_url)->toBe('https://canvas-updated.test');
});

it('tests Canvas connection with the decrypted token', function (): void {
    $user = User::factory()->create();
    $organization = canvasEnvironmentTestOrganization();
    $environment = canvasEnvironmentTestEnvironment($organization, [
        'base_url' => 'https://canvas.test',
        'api_token' => 'secret-token',
    ]);

    Http::fake([
        'https://canvas.test/api/v1/users/self' => Http::response([
            'name' => 'Canvas Tester',
        ], 200),
    ]);

    $this->actingAs($user)
        ->post(route('canvas-environments.test-connection', $environment))
        ->assertRedirect(route('canvas-environments.index'))
        ->assertSessionHas('success', 'Conexão validada com sucesso. Autenticado como: Canvas Tester');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://canvas.test/api/v1/users/self'
            && $request->hasHeader('Authorization', 'Bearer secret-token');
    });
});

it('shows a friendly message when stored Canvas token is invalid during connection test', function (): void {
    $user = User::factory()->create();
    $organization = canvasEnvironmentTestOrganization();
    $environment = canvasEnvironmentTestEnvironment($organization, [
        'api_token' => 'secret-token',
    ]);

    DB::table('canvas_environments')
        ->where('id', $environment->id)
        ->update(['api_token' => 'not-encrypted']);

    Http::fake();

    $this->actingAs($user)
        ->post(route('canvas-environments.test-connection', $environment))
        ->assertRedirect(route('canvas-environments.index'))
        ->assertSessionHas('error', 'Token salvo está inválido ou incompatível. Reinsira o token da instância Canvas.');

    Http::assertNothingSent();
});

it('renders the edit page even when the stored Canvas token is invalid', function (): void {
    $user = User::factory()->create();
    $organization = canvasEnvironmentTestOrganization();
    $environment = canvasEnvironmentTestEnvironment($organization, [
        'api_token' => 'secret-token',
    ]);

    DB::table('canvas_environments')
        ->where('id', $environment->id)
        ->update(['api_token' => 'not-encrypted']);

    $this->actingAs($user)
        ->get(route('canvas-environments.edit', $environment))
        ->assertOk()
        ->assertSee('Token configurado')
        ->assertSee('Sim')
        ->assertSee('O token salvo está inválido ou incompatível. Preencha um novo token para substituir o valor atual.')
        ->assertSee('Preencha somente se desejar substituir o token atual.')
        ->assertDontSee('secret-token')
        ->assertDontSee('not-encrypted');
});

function canvasEnvironmentTestOrganization(): Organization
{
    return Organization::query()->create([
        'name' => 'Organization '.uniqid(),
        'slug' => 'organization-'.uniqid(),
        'timezone' => 'America/Sao_Paulo',
        'is_active' => true,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function canvasEnvironmentTestEnvironment(Organization $organization, array $attributes = []): CanvasEnvironment
{
    return CanvasEnvironment::query()->create(array_merge([
        'organization_id' => $organization->id,
        'name' => 'Canvas Environment',
        'slug' => 'canvas-environment-'.uniqid(),
        'environment_type' => 'test',
        'base_url' => 'https://canvas.test',
        'api_token' => 'secret-token',
        'is_active' => true,
        'is_default' => true,
    ], $attributes));
}
