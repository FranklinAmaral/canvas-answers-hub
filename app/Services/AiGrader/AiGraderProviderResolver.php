<?php

declare(strict_types=1);

namespace App\Services\AiGrader;

use App\Models\AiGraderAiProvider;
use App\Models\AiGraderBlueprintConfig;
use App\Models\AiGraderCorrectionItem;
use App\Services\AiGrader\Contracts\AiGraderProviderInterface;
use App\Services\AiGrader\Providers\LmStudioAiGraderProvider;
use App\Services\AiGrader\Providers\MockAiGraderProvider;
use RuntimeException;

class AiGraderProviderResolver
{
    public function __construct(
        private readonly AiGraderPromptBuilder $promptBuilder,
        private readonly AiGraderSanitizer $sanitizer,
    ) {
    }

    public function resolve(?AiGraderAiProvider $provider = null): AiGraderProviderInterface
    {
        if (! $provider) {
            throw new RuntimeException('No AI provider was resolved for this correction.');
        }

        $this->assertProviderIsUsable($provider);

        if ($provider->provider_type === AiGraderAiProvider::TYPE_MOCK) {
            return new MockAiGraderProvider($provider);
        }

        if ($provider->provider_type === AiGraderAiProvider::TYPE_LM_STUDIO) {
            return new LmStudioAiGraderProvider($provider, $this->promptBuilder, $this->sanitizer);
        }

        throw new RuntimeException(sprintf('AI provider type [%s] is not implemented yet.', $provider->provider_type));
    }

    public function resolveForItem(AiGraderCorrectionItem $item, ?AiGraderAiProvider $overrideProvider = null): AiGraderProviderInterface
    {
        return $this->resolve($this->providerForItem($item, $overrideProvider));
    }

    public function providerForItem(AiGraderCorrectionItem $item, ?AiGraderAiProvider $overrideProvider = null): AiGraderAiProvider
    {
        $item->loadMissing('blueprintConfig.aiProvider');

        return $this->providerForContext(
            (int) $item->organization_id,
            $item->blueprintConfig?->aiProvider,
            $overrideProvider,
        );
    }

    public function providerForBlueprint(AiGraderBlueprintConfig $blueprintConfig): AiGraderAiProvider
    {
        $blueprintConfig->loadMissing('aiProvider');

        return $this->providerForContext(
            (int) $blueprintConfig->organization_id,
            $blueprintConfig->aiProvider,
        );
    }

    private function providerForContext(
        int $organizationId,
        ?AiGraderAiProvider $blueprintProvider = null,
        ?AiGraderAiProvider $overrideProvider = null,
    ): AiGraderAiProvider {
        if ($overrideProvider) {
            $this->assertProviderBelongsToOrganization($overrideProvider, $organizationId);
            $this->assertProviderIsUsable($overrideProvider);

            return $overrideProvider;
        }

        if ($blueprintProvider) {
            $this->assertProviderBelongsToOrganization($blueprintProvider, $organizationId);

            if ($blueprintProvider->enabled) {
                $this->assertProviderIsUsable($blueprintProvider);

                return $blueprintProvider;
            }
        }

        $defaultProviders = AiGraderAiProvider::query()
            ->where('organization_id', $organizationId)
            ->where('enabled', true)
            ->where('is_default', true)
            ->limit(2)
            ->get();

        if ($defaultProviders->count() > 1) {
            throw new RuntimeException('Multiple enabled default AI providers are configured for this organization.');
        }

        $defaultProvider = $defaultProviders->first();

        if ($defaultProvider) {
            $this->assertProviderIsUsable($defaultProvider);

            return $defaultProvider;
        }

        throw new RuntimeException('No enabled AI provider is configured for this organization.');
    }

    private function assertProviderBelongsToOrganization(AiGraderAiProvider $provider, int $organizationId): void
    {
        if ((int) $provider->organization_id !== $organizationId) {
            throw new RuntimeException(sprintf(
                'AI provider [%s] does not belong to the correction organization.',
                $provider->name,
            ));
        }
    }

    private function assertProviderIsUsable(AiGraderAiProvider $provider): void
    {
        if (! $provider->enabled) {
            throw new RuntimeException(sprintf('AI provider [%s] is disabled.', $provider->name));
        }

        if (! in_array($provider->provider_type, AiGraderAiProvider::supportedTypes(), true)) {
            throw new RuntimeException(sprintf('AI provider type [%s] is not supported.', $provider->provider_type));
        }

        if ($provider->provider_type === AiGraderAiProvider::TYPE_MOCK) {
            if (! app()->environment(['local', 'testing'])) {
                throw new RuntimeException('The Mock AI provider is restricted to local and testing environments.');
            }

            return;
        }

        $baseUrl = trim((string) $provider->base_url);
        $scheme = mb_strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
        $endpointPath = $provider->endpointPath();

        if (
            $baseUrl === ''
            || filter_var($baseUrl, FILTER_VALIDATE_URL) === false
            || ! in_array($scheme, ['http', 'https'], true)
            || parse_url($baseUrl, PHP_URL_USER) !== null
            || parse_url($baseUrl, PHP_URL_PASS) !== null
            || parse_url($baseUrl, PHP_URL_QUERY) !== null
            || parse_url($baseUrl, PHP_URL_FRAGMENT) !== null
            || trim((string) $provider->model) === ''
            || ! str_starts_with($endpointPath, '/')
        ) {
            throw new RuntimeException(sprintf('AI provider [%s] has incomplete or invalid configuration.', $provider->name));
        }
    }
}
