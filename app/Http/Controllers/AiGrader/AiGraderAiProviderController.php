<?php

declare(strict_types=1);

namespace App\Http\Controllers\AiGrader;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiGrader\UpsertAiGraderAiProviderRequest;
use App\Models\AiGraderAiProvider;
use App\Models\Organization;
use App\Services\AiGrader\AiGraderProviderResolver;
use App\Services\AiGrader\AiGraderSanitizer;
use App\Services\AiGrader\Providers\LmStudioAiGraderProvider;
use App\Services\AiGrader\Providers\MockAiGraderProvider;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AiGraderAiProviderController extends Controller
{
    public function index(Request $request): View
    {
        $providers = AiGraderAiProvider::query()
            ->with('organization')
            ->withCount('blueprintConfigs')
            ->when($request->filled('organization_id'), fn ($query) => $query->where('organization_id', $request->integer('organization_id')))
            ->when($request->filled('provider_type'), fn ($query) => $query->where('provider_type', $request->string('provider_type')))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('ai-grader.ai-providers.index', [
            'providers' => $providers,
            'organizations' => Organization::query()->orderBy('name')->get(),
            'providerTypes' => $this->providerTypes(),
        ]);
    }

    public function create(): View
    {
        return view('ai-grader.ai-providers.create', $this->formData(new AiGraderAiProvider()));
    }

    public function store(UpsertAiGraderAiProviderRequest $request): RedirectResponse
    {
        $data = $this->providerData($request);
        $data['created_by'] = $request->user()?->id;
        $data['updated_by'] = $request->user()?->id;

        $provider = DB::transaction(function () use ($data): AiGraderAiProvider {
            if ((bool) ($data['is_default'] ?? false)) {
                $this->unsetDefaultProviders((int) $data['organization_id']);
            }

            return AiGraderAiProvider::query()->create($data);
        });

        return redirect()
            ->route('ai-grader.ai-providers.show', $provider)
            ->with('success', 'Provider de IA criado com sucesso.');
    }

    public function show(AiGraderAiProvider $provider): View
    {
        $provider->load('organization')->loadCount('blueprintConfigs');

        return view('ai-grader.ai-providers.show', [
            'provider' => $provider,
        ]);
    }

    public function edit(AiGraderAiProvider $provider): View
    {
        return view('ai-grader.ai-providers.edit', $this->formData($provider));
    }

    public function update(UpsertAiGraderAiProviderRequest $request, AiGraderAiProvider $provider): RedirectResponse
    {
        $data = $this->providerData($request, $provider);
        $data['updated_by'] = $request->user()?->id;

        DB::transaction(function () use ($provider, $data): void {
            if ((bool) ($data['is_default'] ?? false)) {
                $this->unsetDefaultProviders((int) $data['organization_id'], $provider->id);
            }

            $provider->update($data);
        });

        return redirect()
            ->route('ai-grader.ai-providers.show', $provider)
            ->with('success', 'Provider de IA atualizado com sucesso.');
    }

    public function destroy(AiGraderAiProvider $provider): RedirectResponse
    {
        $provider->delete();

        return redirect()
            ->route('ai-grader.ai-providers.index')
            ->with('success', 'Provider de IA removido com sucesso.');
    }

    public function test(
        AiGraderAiProvider $provider,
        AiGraderProviderResolver $providerResolver,
        AiGraderSanitizer $sanitizer,
    ): RedirectResponse
    {
        try {
            $resolvedProvider = $providerResolver->resolve($provider);

            if ($resolvedProvider instanceof MockAiGraderProvider) {
                return back()->with('success', 'Conexao com Mock Provider realizada com sucesso.');
            }

            if (! $resolvedProvider instanceof LmStudioAiGraderProvider) {
                return back()->with('error', 'Nao foi possivel testar o provider: tipo nao suportado.');
            }

            $result = $resolvedProvider->testConnection();
        } catch (Throwable $exception) {
            return back()->with('error', 'Nao foi possivel testar o provider: ' . mb_substr($sanitizer->sanitizeString($exception->getMessage()), 0, 300));
        }

        if ((bool) ($result['ok'] ?? false)) {
            return back()->with('success', 'Conexao com LM Studio realizada com sucesso.');
        }

        return back()->with('error', 'Nao foi possivel conectar ao LM Studio: ' . mb_substr((string) ($result['message'] ?? 'erro desconhecido'), 0, 300));
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(AiGraderAiProvider $provider): array
    {
        return [
            'provider' => $provider,
            'organizations' => Organization::query()->orderBy('name')->get(),
            'providerTypes' => $this->providerTypes(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function providerTypes(): array
    {
        return [
            AiGraderAiProvider::TYPE_MOCK => __('ai-grader.admin.ai_providers.types.mock'),
            AiGraderAiProvider::TYPE_LM_STUDIO => __('ai-grader.admin.ai_providers.types.lm_studio'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function providerData(UpsertAiGraderAiProviderRequest $request, ?AiGraderAiProvider $provider = null): array
    {
        $validated = $request->validated();
        $settings = $this->settingsFromRequest($validated, $provider);

        $data = [
            'organization_id' => (int) $validated['organization_id'],
            'name' => trim((string) $validated['name']),
            'provider_type' => $validated['provider_type'],
            'base_url' => filled($validated['base_url'] ?? null) ? trim((string) $validated['base_url']) : null,
            'model' => filled($validated['model'] ?? null) ? trim((string) $validated['model']) : null,
            'request_timeout_seconds' => $validated['request_timeout_seconds'] ?? null,
            'max_tokens' => $validated['max_tokens'] ?? null,
            'temperature' => $validated['temperature'] ?? null,
            'enabled' => $request->boolean('enabled'),
            'is_default' => $request->boolean('is_default'),
            'settings' => $settings,
        ];

        $apiKey = trim((string) ($validated['api_key'] ?? ''));

        if ($apiKey !== '') {
            $data['api_key_encrypted'] = $apiKey;
        } elseif (! $provider) {
            $data['api_key_encrypted'] = null;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function settingsFromRequest(array $validated, ?AiGraderAiProvider $provider = null): array
    {
        $settings = $provider?->settings ?? [];

        $endpointPath = trim((string) ($validated['endpoint_path'] ?? ''));

        if ($endpointPath !== '') {
            $settings['endpoint_path'] = $endpointPath;
        } else {
            unset($settings['endpoint_path']);
        }

        return $settings;
    }

    private function unsetDefaultProviders(int $organizationId, ?int $exceptId = null): void
    {
        AiGraderAiProvider::query()
            ->where('organization_id', $organizationId)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->update(['is_default' => false]);
    }
}
