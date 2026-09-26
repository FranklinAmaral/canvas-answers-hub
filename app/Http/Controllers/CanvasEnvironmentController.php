<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CanvasEnvironment;
use App\Models\Organization;
use App\Services\Canvas\CanvasApiClient;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CanvasEnvironmentController extends Controller
{
    public function index(Request $request): View
    {
        $environments = CanvasEnvironment::query()
            ->with('organization')
            ->latest()
            ->paginate(15);

        return view('canvas-environments.index', [
            'environments' => $environments,
        ]);
    }

    public function create(Request $request): View
    {
        $organizations = Organization::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('canvas-environments.create', [
            'organizations' => $organizations,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'environment_type' => ['required', 'string', 'max:50'],
            'base_url' => ['required', 'url', 'max:255'],
            'api_token' => ['required', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'lti_enabled' => ['nullable', 'boolean'],
            'lti_issuer' => [
                'nullable',
                'required_if:lti_enabled,1',
                'url:https',
                'max:255',
                Rule::unique('canvas_environments', 'lti_issuer')->where(
                    fn ($query) => $query
                        ->where('lti_client_id', $request->input('lti_client_id'))
                        ->where('lti_deployment_id', $request->input('lti_deployment_id'))
                ),
            ],
            'lti_client_id' => ['nullable', 'required_if:lti_enabled,1', 'string', 'max:255'],
            'lti_deployment_id' => ['nullable', 'required_if:lti_enabled,1', 'string', 'max:255'],
            'lti_authorization_endpoint' => ['nullable', 'required_if:lti_enabled,1', 'url:https', 'max:255'],
            'lti_jwks_uri' => ['nullable', 'required_if:lti_enabled,1', 'url:https', 'max:255'],
        ]);

        $this->assertValidLtiConfiguration($validated);

        $slug = filled($validated['slug'] ?? null)
            ? Str::slug((string) $validated['slug'])
            : Str::slug((string) $validated['name']);

        if (! empty($validated['is_default'])) {
            CanvasEnvironment::query()
                ->where('organization_id', $validated['organization_id'])
                ->update(['is_default' => false]);
        }

        CanvasEnvironment::query()->create([
            'organization_id' => $validated['organization_id'],
            'name' => $validated['name'],
            'slug' => $slug,
            'environment_type' => $validated['environment_type'],
            'base_url' => rtrim((string) $validated['base_url'], '/'),
            'api_token' => $validated['api_token'],
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'is_default' => (bool) ($validated['is_default'] ?? false),
            ...$this->ltiData($validated),
        ]);

        return redirect()
            ->route('canvas-environments.index')
            ->with('success', 'Ambiente Canvas criado com sucesso.');
    }

    public function edit(CanvasEnvironment $canvasEnvironment): View
    {
        $organizations = Organization::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $tokenStatus = $canvasEnvironment->apiTokenStatus();

        return view('canvas-environments.edit', [
            'canvasEnvironment' => $canvasEnvironment,
            'organizations' => $organizations,
            'tokenConfigured' => $tokenStatus['configured'],
            'tokenInvalid' => $tokenStatus['invalid'],
        ]);
    }

    public function update(Request $request, CanvasEnvironment $canvasEnvironment): RedirectResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'environment_type' => ['required', 'string', 'max:50'],
            'base_url' => ['required', 'url', 'max:255'],
            'api_token' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'lti_enabled' => ['nullable', 'boolean'],
            'lti_issuer' => [
                'nullable',
                'required_if:lti_enabled,1',
                'url:https',
                'max:255',
                Rule::unique('canvas_environments', 'lti_issuer')
                    ->where(
                        fn ($query) => $query
                            ->where('lti_client_id', $request->input('lti_client_id'))
                            ->where('lti_deployment_id', $request->input('lti_deployment_id'))
                    )
                    ->ignore($canvasEnvironment->id),
            ],
            'lti_client_id' => ['nullable', 'required_if:lti_enabled,1', 'string', 'max:255'],
            'lti_deployment_id' => ['nullable', 'required_if:lti_enabled,1', 'string', 'max:255'],
            'lti_authorization_endpoint' => ['nullable', 'required_if:lti_enabled,1', 'url:https', 'max:255'],
            'lti_jwks_uri' => ['nullable', 'required_if:lti_enabled,1', 'url:https', 'max:255'],
        ]);

        $this->assertValidLtiConfiguration($validated);

        $slug = filled($validated['slug'] ?? null)
            ? Str::slug((string) $validated['slug'])
            : Str::slug((string) $validated['name']);

        if (! empty($validated['is_default'])) {
            CanvasEnvironment::query()
                ->where('organization_id', $validated['organization_id'])
                ->whereKeyNot($canvasEnvironment->id)
                ->update(['is_default' => false]);
        }

        $data = [
            'organization_id' => $validated['organization_id'],
            'name' => $validated['name'],
            'slug' => $slug,
            'environment_type' => $validated['environment_type'],
            'base_url' => rtrim((string) $validated['base_url'], '/'),
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'is_default' => (bool) ($validated['is_default'] ?? false),
            ...$this->ltiData($validated),
        ];

        $newApiToken = filled($validated['api_token'] ?? null)
            ? trim((string) $validated['api_token'])
            : null;

        DB::transaction(function () use ($canvasEnvironment, $data, $newApiToken): void {
            CanvasEnvironment::query()
                ->whereKey($canvasEnvironment->id)
                ->update($data);

            if ($newApiToken !== null) {
                $canvasEnvironment->replaceApiToken($newApiToken);
            }
        });

        return redirect()
            ->route('canvas-environments.index')
            ->with('success', 'Ambiente Canvas atualizado com sucesso.');
    }

    public function testConnection(CanvasEnvironment $canvasEnvironment): RedirectResponse
    {
        try {
            $client = new CanvasApiClient($canvasEnvironment);
            $user = $client->testConnection();

            $userName = (string) ($user['name'] ?? 'Usuário autenticado');

            return redirect()
                ->route('canvas-environments.index')
                ->with('success', "Conexão validada com sucesso. Autenticado como: {$userName}");
        } catch (DecryptException $e) {
            Log::warning('Canvas environment test connection failed due to invalid token payload', [
                'environment_id' => $canvasEnvironment->id,
                'base_url' => $canvasEnvironment->base_url,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->route('canvas-environments.index')
                ->with('error', 'Token salvo está inválido ou incompatível. Reinsira o token da instância Canvas.');
        } catch (Throwable $e) {
            Log::warning('Canvas environment test connection failed', [
                'environment_id' => $canvasEnvironment->id,
                'base_url' => $canvasEnvironment->base_url,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->route('canvas-environments.index')
                ->with('error', 'Falha no teste de conexão: '.$e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function ltiData(array $validated): array
    {
        $enabled = (bool) ($validated['lti_enabled'] ?? false);

        return [
            'lti_enabled' => $enabled,
            'lti_issuer' => $this->normalizedLtiValue($validated['lti_issuer'] ?? null),
            'lti_client_id' => $this->normalizedLtiValue($validated['lti_client_id'] ?? null),
            'lti_deployment_id' => $this->normalizedLtiValue($validated['lti_deployment_id'] ?? null),
            'lti_authorization_endpoint' => $this->normalizedLtiValue($validated['lti_authorization_endpoint'] ?? null),
            'lti_jwks_uri' => $this->normalizedLtiValue($validated['lti_jwks_uri'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertValidLtiConfiguration(array $validated): void
    {
        if (empty($validated['lti_enabled'])) {
            return;
        }

        if (empty($validated['is_active'])) {
            throw ValidationException::withMessages([
                'lti_enabled' => 'Uma instalação LTI ativa exige que o ambiente Canvas esteja ativo.',
            ]);
        }

        if (! Organization::query()
            ->whereKey((int) ($validated['organization_id'] ?? 0))
            ->where('is_active', true)
            ->exists()) {
            throw ValidationException::withMessages([
                'organization_id' => 'Uma instalação LTI ativa exige uma organização ativa.',
            ]);
        }

        foreach (['lti_authorization_endpoint', 'lti_jwks_uri'] as $field) {
            $url = (string) ($validated[$field] ?? '');

            if (parse_url($url, PHP_URL_USER) !== null
                || parse_url($url, PHP_URL_PASS) !== null
                || parse_url($url, PHP_URL_QUERY) !== null
                || parse_url($url, PHP_URL_FRAGMENT) !== null) {
                throw ValidationException::withMessages([
                    $field => 'O endpoint LTI deve ser uma URL HTTPS sem credenciais, query string ou fragmento.',
                ]);
            }
        }
    }

    private function normalizedLtiValue(mixed $value, bool $trimTrailingSlash = false): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);

        return $trimTrailingSlash ? rtrim($value, '/') : $value;
    }
}
