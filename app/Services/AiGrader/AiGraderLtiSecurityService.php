<?php

declare(strict_types=1);

namespace App\Services\AiGrader;

use App\Models\CanvasEnvironment;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

class AiGraderLtiSecurityService
{
    private const STATE_CACHE_PREFIX = 'ai_grader_lti_state:';

    private const MESSAGE_TYPE_CLAIM = 'https://purl.imsglobal.org/spec/lti/claim/message_type';

    private const VERSION_CLAIM = 'https://purl.imsglobal.org/spec/lti/claim/version';

    private const DEPLOYMENT_CLAIM = 'https://purl.imsglobal.org/spec/lti/claim/deployment_id';

    private const TARGET_LINK_CLAIM = 'https://purl.imsglobal.org/spec/lti/claim/target_link_uri';

    private const ROLES_CLAIM = 'https://purl.imsglobal.org/spec/lti/claim/roles';

    private const CUSTOM_CLAIM = 'https://purl.imsglobal.org/spec/lti/claim/custom';

    private const CONTEXT_CLAIM = 'https://purl.imsglobal.org/spec/lti/claim/context';

    public function __construct(private readonly AiGraderLtiConfigService $configService) {}

    /**
     * @param  array<string, string>  $input
     * @return array{authorization_url: string, state: string, nonce: string, environment: CanvasEnvironment}
     */
    public function initiate(array $input): array
    {
        $environment = $this->environmentForRegistration(
            $input['iss'],
            $input['client_id'],
            $input['lti_deployment_id'],
        );

        if (! hash_equals($this->configService->urls()['launch'], $input['target_link_uri'])) {
            throw new AiGraderLtiSecurityException('invalid_target_link_uri', 'The LTI target link URI is not registered.', 422);
        }

        $this->assertSecureEndpoint((string) $environment->lti_authorization_endpoint, 'authorization_endpoint');
        $this->assertSecureEndpoint((string) $environment->lti_jwks_uri, 'jwks_uri');

        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));

        Cache::put($this->stateKey($state), [
            'nonce' => $nonce,
            'canvas_environment_id' => $environment->id,
            'organization_id' => $environment->organization_id,
            'iss' => $environment->lti_issuer,
            'client_id' => $environment->lti_client_id,
            'deployment_id' => $environment->lti_deployment_id,
            'target_link_uri' => $input['target_link_uri'],
            'created_at' => now()->timestamp,
        ], now()->addSeconds($this->positiveConfig('graderai.lti.state_ttl_seconds', 600)));

        $query = http_build_query([
            'scope' => 'openid',
            'response_type' => 'id_token',
            'response_mode' => 'form_post',
            'prompt' => 'none',
            'client_id' => $environment->lti_client_id,
            'redirect_uri' => $input['target_link_uri'],
            'login_hint' => $input['login_hint'],
            'state' => $state,
            'nonce' => $nonce,
            'lti_message_hint' => $input['lti_message_hint'],
        ], '', '&', PHP_QUERY_RFC3986);

        $authorizationEndpoint = rtrim((string) $environment->lti_authorization_endpoint, '?');

        return [
            'authorization_url' => $authorizationEndpoint.(str_contains($authorizationEndpoint, '?') ? '&' : '?').$query,
            'state' => $state,
            'nonce' => $nonce,
            'environment' => $environment,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validateLaunch(string $state, string $idToken): array
    {
        $cachedState = $this->consumeState($state);
        $environment = CanvasEnvironment::query()
            ->with('organization')
            ->whereKey((int) ($cachedState['canvas_environment_id'] ?? 0))
            ->first();

        $this->assertEnvironmentIsAvailable($environment);

        $this->assertStateStillMatchesRegistration($cachedState, $environment);
        $claims = $this->verifiedClaims($idToken, $environment);
        $this->validateClaims($claims, $cachedState, $environment);

        return $this->contextFromClaims($claims, $environment);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function environmentForContext(array $context): ?CanvasEnvironment
    {
        $environmentId = filter_var($context['canvas_environment_id'] ?? null, FILTER_VALIDATE_INT);
        $installationId = filter_var($context['lti_installation_id'] ?? null, FILTER_VALIDATE_INT);

        if ($environmentId === false || $installationId === false || $environmentId !== $installationId) {
            return null;
        }

        return CanvasEnvironment::query()
            ->with('organization')
            ->whereKey($environmentId)
            ->where('organization_id', (int) ($context['organization_id'] ?? 0))
            ->where('lti_issuer', (string) ($context['issuer'] ?? ''))
            ->where('lti_client_id', (string) ($context['client_id'] ?? ''))
            ->where('lti_deployment_id', (string) ($context['deployment_id'] ?? ''))
            ->where('is_active', true)
            ->where('lti_enabled', true)
            ->whereHas('organization', fn ($query) => $query->where('is_active', true))
            ->first();
    }

    private function environmentForRegistration(string $issuer, string $clientId, string $deploymentId): CanvasEnvironment
    {
        $matches = CanvasEnvironment::query()
            ->with('organization')
            ->where('lti_issuer', $issuer)
            ->where('lti_client_id', $clientId)
            ->where('lti_deployment_id', $deploymentId)
            ->limit(2)
            ->get();

        if ($matches->count() !== 1) {
            throw new AiGraderLtiSecurityException('unknown_installation', 'No active LTI installation matches the request.', 422);
        }

        $environment = $matches->first();
        $this->assertEnvironmentIsAvailable($environment);

        return $environment;
    }

    private function assertEnvironmentIsAvailable(?CanvasEnvironment $environment): void
    {
        if (! $environment instanceof CanvasEnvironment) {
            throw new AiGraderLtiSecurityException('unknown_installation', 'The LTI installation is unknown.', 422);
        }

        if (! $environment->lti_enabled) {
            throw new AiGraderLtiSecurityException('installation_inactive', 'The LTI installation is inactive.', 403);
        }

        if (! $environment->is_active) {
            throw new AiGraderLtiSecurityException('canvas_environment_inactive', 'The Canvas environment is inactive.', 403);
        }

        if (! $environment->organization?->is_active) {
            throw new AiGraderLtiSecurityException('organization_inactive', 'The organization is inactive.', 403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function consumeState(string $state): array
    {
        if ($state === '') {
            throw new AiGraderLtiSecurityException('missing_state', 'The LTI state is required.', 400);
        }

        $cacheKey = $this->stateKey($state);
        $value = Cache::lock($cacheKey.':lock', 5)->get(fn (): mixed => Cache::pull($cacheKey));

        if (! is_array($value)) {
            throw new AiGraderLtiSecurityException('invalid_or_replayed_state', 'The LTI state is invalid, expired, or already used.', 400);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function verifiedClaims(string $idToken, CanvasEnvironment $environment): array
    {
        if ($idToken === '') {
            throw new AiGraderLtiSecurityException('missing_id_token', 'The LTI id_token is required.', 400);
        }

        $header = $this->jwtHeader($idToken);
        $algorithm = is_string($header['alg'] ?? null) ? $header['alg'] : '';
        $kid = is_string($header['kid'] ?? null) ? trim($header['kid']) : '';

        if ($algorithm !== 'RS256') {
            throw new AiGraderLtiSecurityException('algorithm_not_allowed', 'The JWT signing algorithm is not allowed.');
        }

        if ($kid === '') {
            throw new AiGraderLtiSecurityException('missing_kid', 'The JWT key ID is required.');
        }

        $key = $this->keyFor($environment, $kid);
        $previousLeeway = JWT::$leeway;
        JWT::$leeway = $this->positiveConfig('graderai.lti.clock_skew_seconds', 60);

        try {
            $payload = JWT::decode($idToken, $key);
        } catch (ExpiredException $exception) {
            throw new AiGraderLtiSecurityException('token_expired', 'The LTI id_token has expired.', 401, $exception);
        } catch (BeforeValidException $exception) {
            throw new AiGraderLtiSecurityException('invalid_iat', 'The LTI id_token timestamp is invalid.', 401, $exception);
        } catch (SignatureInvalidException $exception) {
            throw new AiGraderLtiSecurityException('invalid_signature', 'The LTI id_token signature is invalid.', 401, $exception);
        } catch (Throwable $exception) {
            throw new AiGraderLtiSecurityException('invalid_id_token', 'The LTI id_token is malformed or invalid.', 401, $exception);
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        try {
            $claims = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AiGraderLtiSecurityException('invalid_id_token', 'The LTI id_token claims are invalid.', 401, $exception);
        }

        return is_array($claims) ? $claims : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function jwtHeader(string $idToken): array
    {
        $parts = explode('.', $idToken);

        if (count($parts) !== 3 || in_array('', $parts, true)) {
            throw new AiGraderLtiSecurityException('invalid_id_token', 'The LTI id_token is malformed.');
        }

        $decoded = $this->base64UrlDecode($parts[0]);

        try {
            $header = json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AiGraderLtiSecurityException('invalid_id_token', 'The LTI id_token header is invalid.', 401, $exception);
        }

        return is_array($header) ? $header : [];
    }

    private function keyFor(CanvasEnvironment $environment, string $kid): Key
    {
        $jwks = $this->jwks($environment);
        $jwk = $this->matchingJwk($jwks, $kid);

        if ($jwk === null) {
            Cache::forget($this->jwksCacheKey($environment));
            $jwk = $this->matchingJwk($this->jwks($environment), $kid);
        }

        if ($jwk === null) {
            throw new AiGraderLtiSecurityException('unknown_kid', 'No trusted JWKS key matches the JWT key ID.');
        }

        try {
            $keys = JWK::parseKeySet(['keys' => [$jwk]], 'RS256');
        } catch (Throwable $exception) {
            throw new AiGraderLtiSecurityException('invalid_jwks', 'The registered JWKS contains an invalid key.', 401, $exception);
        }

        if (! isset($keys[$kid])) {
            throw new AiGraderLtiSecurityException('unknown_kid', 'No trusted JWKS key matches the JWT key ID.');
        }

        return $keys[$kid];
    }

    /**
     * @return array<string, mixed>
     */
    private function jwks(CanvasEnvironment $environment): array
    {
        $this->assertSecureEndpoint((string) $environment->lti_jwks_uri, 'jwks_uri');

        return Cache::remember(
            $this->jwksCacheKey($environment),
            now()->addSeconds($this->positiveConfig('graderai.lti.jwks_cache_seconds', 300)),
            function () use ($environment): array {
                try {
                    $response = Http::acceptJson()
                        ->timeout(5)
                        ->withoutRedirecting()
                        ->get((string) $environment->lti_jwks_uri);
                } catch (Throwable $exception) {
                    throw new AiGraderLtiSecurityException('jwks_unavailable', 'The registered JWKS could not be loaded.', 503, $exception);
                }

                return $this->jwksFromResponse($response);
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function jwksFromResponse(Response $response): array
    {
        if (! $response->successful()) {
            throw new AiGraderLtiSecurityException('jwks_unavailable', 'The registered JWKS could not be loaded.', 503);
        }

        $jwks = $response->json();

        if (! is_array($jwks) || ! is_array($jwks['keys'] ?? null)) {
            throw new AiGraderLtiSecurityException('invalid_jwks', 'The registered JWKS response is invalid.');
        }

        return $jwks;
    }

    /**
     * @param  array<string, mixed>  $jwks
     * @return array<string, mixed>|null
     */
    private function matchingJwk(array $jwks, string $kid): ?array
    {
        foreach ($jwks['keys'] ?? [] as $jwk) {
            if (! is_array($jwk)
                || ($jwk['kid'] ?? null) !== $kid
                || ($jwk['kty'] ?? null) !== 'RSA'
                || isset($jwk['d'])
                || (isset($jwk['alg']) && $jwk['alg'] !== 'RS256')
                || (isset($jwk['use']) && $jwk['use'] !== 'sig')) {
                continue;
            }

            return $jwk;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $state
     */
    private function validateClaims(array $claims, array $state, CanvasEnvironment $environment): void
    {
        if (! is_string($claims['iss'] ?? null) || ! hash_equals((string) $environment->lti_issuer, $claims['iss'])) {
            throw new AiGraderLtiSecurityException('invalid_issuer', 'The LTI issuer does not match the installation.');
        }

        $audienceClaim = $claims['aud'] ?? null;
        $audiences = is_string($audienceClaim) ? [$audienceClaim] : $audienceClaim;

        if (! is_array($audiences)
            || $audiences === []
            || collect($audiences)->contains(fn (mixed $audience): bool => ! is_string($audience) || trim($audience) === '')) {
            throw new AiGraderLtiSecurityException('invalid_audience', 'The LTI audience claim is invalid.');
        }

        $audiences = array_values($audiences);

        if (! in_array((string) $environment->lti_client_id, $audiences, true)) {
            throw new AiGraderLtiSecurityException('invalid_audience', 'The LTI audience does not contain the registered client ID.');
        }

        if (count($audiences) > 1 && ($claims['azp'] ?? null) !== $environment->lti_client_id) {
            throw new AiGraderLtiSecurityException('invalid_authorized_party', 'The LTI authorized party is invalid.');
        }

        $now = now()->timestamp;
        $skew = $this->positiveConfig('graderai.lti.clock_skew_seconds', 60);
        $maxAge = $this->positiveConfig('graderai.lti.max_token_age_seconds', 600);

        if (! is_numeric($claims['exp'] ?? null) || (int) $claims['exp'] <= $now - $skew) {
            throw new AiGraderLtiSecurityException('token_expired', 'The LTI id_token has expired.');
        }

        if (! is_numeric($claims['iat'] ?? null)
            || (int) $claims['iat'] > $now + $skew
            || (int) $claims['iat'] < $now - $maxAge - $skew) {
            throw new AiGraderLtiSecurityException('invalid_iat', 'The LTI id_token timestamp is invalid.');
        }

        $nonce = is_string($claims['nonce'] ?? null) ? $claims['nonce'] : '';
        $expectedNonce = is_string($state['nonce'] ?? null) ? $state['nonce'] : '';

        if ($nonce === '' || $expectedNonce === '' || ! hash_equals($expectedNonce, $nonce)) {
            throw new AiGraderLtiSecurityException('invalid_nonce', 'The LTI nonce is missing or does not match.');
        }

        if (($claims[self::DEPLOYMENT_CLAIM] ?? null) !== $environment->lti_deployment_id) {
            throw new AiGraderLtiSecurityException('invalid_deployment', 'The LTI deployment does not match the installation.');
        }

        if (($claims[self::MESSAGE_TYPE_CLAIM] ?? null) !== 'LtiResourceLinkRequest') {
            throw new AiGraderLtiSecurityException('invalid_message_type', 'The LTI message type is not supported.');
        }

        if (($claims[self::VERSION_CLAIM] ?? null) !== '1.3.0') {
            throw new AiGraderLtiSecurityException('invalid_lti_version', 'The LTI version is not supported.');
        }

        if (($claims[self::TARGET_LINK_CLAIM] ?? null) !== $this->configService->urls()['launch']) {
            throw new AiGraderLtiSecurityException('invalid_target_link_uri', 'The LTI target link URI is invalid.');
        }

        if (! is_array($claims[self::ROLES_CLAIM] ?? null) || $claims[self::ROLES_CLAIM] === []) {
            throw new AiGraderLtiSecurityException('missing_roles', 'The LTI roles claim is required.', 403);
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function assertStateStillMatchesRegistration(array $state, CanvasEnvironment $environment): void
    {
        foreach ([
            'organization_id' => (int) $environment->organization_id,
            'iss' => (string) $environment->lti_issuer,
            'client_id' => (string) $environment->lti_client_id,
            'deployment_id' => (string) $environment->lti_deployment_id,
            'target_link_uri' => $this->configService->urls()['launch'],
        ] as $key => $expected) {
            $actual = $key === 'organization_id' ? (int) ($state[$key] ?? 0) : (string) ($state[$key] ?? '');

            if ($actual !== $expected) {
                throw new AiGraderLtiSecurityException('state_registration_mismatch', 'The LTI state no longer matches its installation.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return array<string, mixed>
     */
    private function contextFromClaims(array $claims, CanvasEnvironment $environment): array
    {
        $custom = is_array($claims[self::CUSTOM_CLAIM] ?? null) ? $claims[self::CUSTOM_CLAIM] : [];
        $context = is_array($claims[self::CONTEXT_CLAIM] ?? null) ? $claims[self::CONTEXT_CLAIM] : [];
        $roles = collect($claims[self::ROLES_CLAIM] ?? [])
            ->filter(fn (mixed $role): bool => is_string($role) && trim($role) !== '')
            ->map(fn (string $role): string => trim($role))
            ->values()
            ->all();

        return [
            'lti_authenticated' => true,
            'lti_installation_id' => $environment->id,
            'organization_id' => $environment->organization_id,
            'canvas_environment_id' => $environment->id,
            'issuer' => $environment->lti_issuer,
            'client_id' => $environment->lti_client_id,
            'deployment_id' => $environment->lti_deployment_id,
            'course_id' => $this->stringOrNull($custom['canvas_course_id'] ?? $context['id'] ?? null),
            'account_id' => $this->stringOrNull($custom['canvas_account_id'] ?? null),
            'canvas_user_id' => $this->stringOrNull($custom['canvas_user_id'] ?? $claims['sub'] ?? null),
            'name' => $this->stringOrNull($claims['name'] ?? null),
            'login' => $this->stringOrNull($custom['canvas_user_login_id'] ?? $claims['preferred_username'] ?? $claims['email'] ?? null),
            'email' => $this->stringOrNull($claims['email'] ?? null),
            'roles' => $roles,
            'launch_id' => $this->stringOrNull($claims['jti'] ?? $claims['nonce'] ?? null),
            'context_title' => $this->stringOrNull($context['title'] ?? null),
            'expires_at' => now()->addMinutes($this->positiveConfig('graderai.lti.session_ttl_minutes', 120))->timestamp,
        ];
    }

    private function assertSecureEndpoint(string $url, string $field): void
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || mb_strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null
            || parse_url($url, PHP_URL_QUERY) !== null
            || parse_url($url, PHP_URL_FRAGMENT) !== null) {
            throw new AiGraderLtiSecurityException('invalid_'.$field, 'The persisted LTI endpoint is invalid.', 422);
        }
    }

    private function stateKey(string $state): string
    {
        return self::STATE_CACHE_PREFIX.hash('sha256', $state);
    }

    private function jwksCacheKey(CanvasEnvironment $environment): string
    {
        return 'ai_grader_lti_jwks:'.$environment->id.':'.hash('sha256', (string) $environment->lti_jwks_uri);
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;

        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if (! is_string($decoded)) {
            throw new AiGraderLtiSecurityException('invalid_id_token', 'The LTI id_token encoding is invalid.');
        }

        return $decoded;
    }

    private function positiveConfig(string $key, int $default): int
    {
        $value = (int) config($key, $default);

        return $value > 0 ? $value : $default;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
