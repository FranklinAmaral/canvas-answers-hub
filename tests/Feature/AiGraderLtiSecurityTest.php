<?php

declare(strict_types=1);

use App\Models\AiGraderClientSetting;
use App\Models\CanvasEnvironment;
use App\Models\Organization;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const LTI_SECURITY_ISSUER = 'https://canvas.instructure.com';
const LTI_SECURITY_CLIENT_ID = 'secure-client-id';
const LTI_SECURITY_DEPLOYMENT_ID = 'secure-deployment-id';
const LTI_SECURITY_KID = 'canvas-key-1';

/**
 * @return array{private: string, jwk: array<string, string>}
 */
function ltiSecurityRsaKey(string $kid = LTI_SECURITY_KID): array
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    expect($key)->not->toBeFalse();
    openssl_pkey_export($key, $privateKey);
    $details = openssl_pkey_get_details($key);
    $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

    return [
        'private' => $privateKey,
        'jwk' => [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $kid,
            'n' => $encode($details['rsa']['n']),
            'e' => $encode($details['rsa']['e']),
        ],
    ];
}

function ltiSecurityEnvironment(array $overrides = []): CanvasEnvironment
{
    $organization = Organization::query()->create([
        'name' => 'Secure LTI Organization',
        'slug' => 'secure-lti-'.uniqid(),
        'timezone' => 'America/Sao_Paulo',
        'is_active' => true,
    ]);

    AiGraderClientSetting::query()->create([
        'organization_id' => $organization->id,
        'enabled' => true,
        'status' => AiGraderClientSetting::STATUS_ACTIVE,
        'trial_quota_total' => 50,
    ]);

    return CanvasEnvironment::query()->create(array_merge([
        'organization_id' => $organization->id,
        'name' => 'Secure Canvas',
        'slug' => 'secure-canvas-'.uniqid(),
        'environment_type' => 'production',
        'base_url' => 'https://canvas.example.test',
        'api_token' => 'local-test-token',
        'is_active' => true,
        'is_default' => true,
        'lti_enabled' => true,
        'lti_issuer' => LTI_SECURITY_ISSUER,
        'lti_client_id' => LTI_SECURITY_CLIENT_ID,
        'lti_deployment_id' => LTI_SECURITY_DEPLOYMENT_ID,
        'lti_authorization_endpoint' => 'https://canvas.example.test/api/lti/authorize_redirect',
        'lti_jwks_uri' => 'https://canvas.example.test/api/lti/security/jwks',
    ], $overrides));
}

/**
 * @return array{state: string, nonce: string}
 */
function ltiSecurityLogin(object $test, array $overrides = []): array
{
    $response = $test->post('http://graderai.example.test/lti/login', array_merge([
        'iss' => LTI_SECURITY_ISSUER,
        'login_hint' => 'opaque-login-hint',
        'client_id' => LTI_SECURITY_CLIENT_ID,
        'target_link_uri' => 'https://graderai.example.test/lti/launch',
        'lti_message_hint' => 'opaque-message-hint',
        'lti_deployment_id' => LTI_SECURITY_DEPLOYMENT_ID,
    ], $overrides));

    $response->assertRedirect();
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

    return [
        'state' => (string) $query['state'],
        'nonce' => (string) $query['nonce'],
    ];
}

/**
 * @return array<string, mixed>
 */
function ltiSecurityClaims(string $nonce, array $overrides = []): array
{
    return array_merge([
        'iss' => LTI_SECURITY_ISSUER,
        'aud' => LTI_SECURITY_CLIENT_ID,
        'sub' => 'canvas-user-2001',
        'iat' => now()->timestamp,
        'exp' => now()->addMinutes(5)->timestamp,
        'nonce' => $nonce,
        'jti' => 'launch-'.uniqid(),
        'name' => 'Secure Teacher',
        'email' => 'teacher@example.test',
        'https://purl.imsglobal.org/spec/lti/claim/deployment_id' => LTI_SECURITY_DEPLOYMENT_ID,
        'https://purl.imsglobal.org/spec/lti/claim/message_type' => 'LtiResourceLinkRequest',
        'https://purl.imsglobal.org/spec/lti/claim/version' => '1.3.0',
        'https://purl.imsglobal.org/spec/lti/claim/target_link_uri' => 'https://graderai.example.test/lti/launch',
        'https://purl.imsglobal.org/spec/lti/claim/roles' => [
            'http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor',
        ],
        'https://purl.imsglobal.org/spec/lti/claim/custom' => [
            'canvas_course_id' => '11',
            'canvas_account_id' => '77',
            'canvas_user_id' => '2001',
            'canvas_user_login_id' => 'teacher.one',
        ],
        'https://purl.imsglobal.org/spec/lti/claim/context' => [
            'id' => '11',
            'title' => 'Secure Course',
        ],
    ], $overrides);
}

function ltiSecurityToken(array $claims, string $privateKey, string $kid = LTI_SECURITY_KID, string $algorithm = 'RS256'): string
{
    return JWT::encode($claims, $privateKey, $algorithm, $kid);
}

beforeEach(function (): void {
    $this->ltiKey = ltiSecurityRsaKey();
    $this->ltiEnvironment = ltiSecurityEnvironment();

    Http::fake(fn (Request $request) => match ($request->url()) {
        'https://canvas.example.test/api/lti/security/jwks' => Http::response(['keys' => [$this->ltiKey['jwk']]]),
        'https://canvas.example.test/api/v1/courses/11' => Http::response([
            'id' => 11,
            'name' => 'Secure Course',
            'blueprint' => false,
        ]),
        default => Http::response([], 404),
    });
});

it('accepts a correctly signed LTI launch and stores only validated context', function (): void {
    $flow = ltiSecurityLogin($this);
    $token = ltiSecurityToken(ltiSecurityClaims($flow['nonce']), $this->ltiKey['private']);

    $this->post('http://graderai.example.test/lti/launch', ['state' => $flow['state'], 'id_token' => $token])
        ->assertOk();

    $context = session('ai_grader_lti_context');
    expect($context['lti_authenticated'])->toBeTrue()
        ->and($context['canvas_environment_id'])->toBe($this->ltiEnvironment->id)
        ->and($context['organization_id'])->toBe($this->ltiEnvironment->organization_id)
        ->and($context['course_id'])->toBe('11')
        ->and($context)->not->toHaveKey('id_token');
});

it('rejects an invalid signature', function (): void {
    $flow = ltiSecurityLogin($this);
    $otherKey = ltiSecurityRsaKey();
    $token = ltiSecurityToken(ltiSecurityClaims($flow['nonce']), $otherKey['private']);

    $this->post('http://graderai.example.test/lti/launch', ['state' => $flow['state'], 'id_token' => $token])
        ->assertUnauthorized()
        ->assertSee('invalid_signature');
});

it('rejects a malformed id token', function (): void {
    $flow = ltiSecurityLogin($this);

    $this->post('http://graderai.example.test/lti/launch', [
        'state' => $flow['state'],
        'id_token' => 'not-a-jwt',
    ])
        ->assertUnauthorized()
        ->assertSee('invalid_id_token');
});

it('rejects an unsigned JWT and a disallowed algorithm', function (string $token, string $reason): void {
    $flow = ltiSecurityLogin($this);
    $token = str_replace('__NONCE__', $flow['nonce'], $token);

    $this->post('http://graderai.example.test/lti/launch', ['state' => $flow['state'], 'id_token' => $token])
        ->assertUnauthorized()
        ->assertSee($reason);
})->with([
    'none' => [fn (): string => rtrim(strtr(base64_encode('{"alg":"none","kid":"canvas-key-1"}'), '+/', '-_'), '=').'.'
        .rtrim(strtr(base64_encode('{"nonce":"__NONCE__"}'), '+/', '-_'), '=').'.', 'invalid_id_token'],
    'HS256' => [fn (): string => JWT::encode(['nonce' => '__NONCE__'], str_repeat('x', 32), 'HS256', LTI_SECURITY_KID), 'algorithm_not_allowed'],
]);

it('rejects invalid issuer, audience, deployment, expiration and issued-at claims', function (array $override, string $reason): void {
    $flow = ltiSecurityLogin($this);
    $token = ltiSecurityToken(ltiSecurityClaims($flow['nonce'], $override), $this->ltiKey['private']);

    $this->post('http://graderai.example.test/lti/launch', ['state' => $flow['state'], 'id_token' => $token])
        ->assertUnauthorized()
        ->assertSee($reason);
})->with([
    'issuer' => [['iss' => 'https://canvas.attacker.test'], 'invalid_issuer'],
    'audience/client ID' => [['aud' => 'another-client'], 'invalid_audience'],
    'deployment' => [['https://purl.imsglobal.org/spec/lti/claim/deployment_id' => 'another-deployment'], 'invalid_deployment'],
    'expired' => [['exp' => 1], 'token_expired'],
    'future iat' => [['iat' => 4102444800], 'invalid_iat'],
]);

it('validates azp when the token has multiple audiences', function (): void {
    $flow = ltiSecurityLogin($this);
    $token = ltiSecurityToken(ltiSecurityClaims($flow['nonce'], [
        'aud' => [LTI_SECURITY_CLIENT_ID, 'other-client'],
        'azp' => 'other-client',
    ]), $this->ltiKey['private']);

    $this->post('http://graderai.example.test/lti/launch', ['state' => $flow['state'], 'id_token' => $token])
        ->assertUnauthorized()
        ->assertSee('invalid_authorized_party');
});

it('requires a valid unexpired one-time state', function (string $stateMode, string $reason): void {
    $flow = ltiSecurityLogin($this);
    $state = $stateMode === 'missing' ? '' : 'unknown-state';
    $token = ltiSecurityToken(ltiSecurityClaims($flow['nonce']), $this->ltiKey['private']);

    $this->post('http://graderai.example.test/lti/launch', ['state' => $state, 'id_token' => $token])
        ->assertBadRequest()
        ->assertSee($reason);
})->with([
    'missing' => ['missing', 'missing_state'],
    'unknown or expired' => ['unknown', 'invalid_or_replayed_state'],
]);

it('rejects an expired state', function (): void {
    config()->set('graderai.lti.state_ttl_seconds', 1);
    $flow = ltiSecurityLogin($this);
    $token = ltiSecurityToken(ltiSecurityClaims($flow['nonce']), $this->ltiKey['private']);

    $this->travel(2)->seconds();

    $this->post('http://graderai.example.test/lti/launch', ['state' => $flow['state'], 'id_token' => $token])
        ->assertBadRequest()
        ->assertSee('invalid_or_replayed_state');
});

it('rejects state replay', function (): void {
    $flow = ltiSecurityLogin($this);
    $token = ltiSecurityToken(ltiSecurityClaims($flow['nonce']), $this->ltiKey['private']);

    $this->post('http://graderai.example.test/lti/launch', ['state' => $flow['state'], 'id_token' => $token])->assertOk();
    $this->post('http://graderai.example.test/lti/launch', ['state' => $flow['state'], 'id_token' => $token])
        ->assertBadRequest()
        ->assertSee('invalid_or_replayed_state');
});

it('rejects a divergent or absent nonce', function (mixed $nonce): void {
    $flow = ltiSecurityLogin($this);
    $claims = ltiSecurityClaims($flow['nonce']);

    if ($nonce === null) {
        unset($claims['nonce']);
    } else {
        $claims['nonce'] = $nonce;
    }

    $token = ltiSecurityToken($claims, $this->ltiKey['private']);

    $this->post('http://graderai.example.test/lti/launch', ['state' => $flow['state'], 'id_token' => $token])
        ->assertUnauthorized()
        ->assertSee('invalid_nonce');
})->with(['divergent' => 'wrong-nonce', 'absent' => null]);

it('rejects an invalid LTI message type or version', function (array $override, string $reason): void {
    $flow = ltiSecurityLogin($this);
    $token = ltiSecurityToken(ltiSecurityClaims($flow['nonce'], $override), $this->ltiKey['private']);

    $this->post('http://graderai.example.test/lti/launch', ['state' => $flow['state'], 'id_token' => $token])
        ->assertUnauthorized()
        ->assertSee($reason);
})->with([
    'message type' => [[
        'https://purl.imsglobal.org/spec/lti/claim/message_type' => 'LtiDeepLinkingRequest',
    ], 'invalid_message_type'],
    'version' => [[
        'https://purl.imsglobal.org/spec/lti/claim/version' => '1.2.0',
    ], 'invalid_lti_version'],
]);

it('rejects an unknown JWKS key ID', function (): void {
    $flow = ltiSecurityLogin($this);
    $token = ltiSecurityToken(ltiSecurityClaims($flow['nonce']), $this->ltiKey['private'], 'unknown-key');

    $this->post('http://graderai.example.test/lti/launch', ['state' => $flow['state'], 'id_token' => $token])
        ->assertUnauthorized()
        ->assertSee('unknown_kid');
});

it('publishes a stable public JWKS only when an application signing key is configured', function (): void {
    config()->set('graderai.lti.signing_private_key', $this->ltiKey['private']);
    config()->set('graderai.lti.signing_key_id', 'graderai-signing-key');

    $this->get('http://graderai.example.test/lti/jwks')
        ->assertOk()
        ->assertJsonPath('keys.0.kty', 'RSA')
        ->assertJsonPath('keys.0.alg', 'RS256')
        ->assertJsonPath('keys.0.kid', 'graderai-signing-key')
        ->assertJsonMissingPath('keys.0.d');

    $this->get('http://graderai.example.test/lti/config')
        ->assertOk()
        ->assertJsonPath('public_jwk_url', 'https://graderai.example.test/lti/jwks');
});

it('rejects inactive installations and inactive Canvas environments', function (array $update, string $reason): void {
    $this->ltiEnvironment->update($update);

    $this->post('http://graderai.example.test/lti/login', [
        'iss' => LTI_SECURITY_ISSUER,
        'login_hint' => 'opaque',
        'client_id' => LTI_SECURITY_CLIENT_ID,
        'target_link_uri' => 'https://graderai.example.test/lti/launch',
        'lti_message_hint' => 'opaque',
        'lti_deployment_id' => LTI_SECURITY_DEPLOYMENT_ID,
    ])->assertStatus(403)->assertSee($reason);
})->with([
    'installation' => [['lti_enabled' => false], 'installation_inactive'],
    'environment' => [['is_active' => false], 'canvas_environment_inactive'],
]);

it('invalidates an established LTI session when its installation is disabled', function (): void {
    $flow = ltiSecurityLogin($this);
    $token = ltiSecurityToken(ltiSecurityClaims($flow['nonce']), $this->ltiKey['private']);

    $this->post('http://graderai.example.test/lti/launch', ['state' => $flow['state'], 'id_token' => $token])
        ->assertOk();

    $this->ltiEnvironment->update(['lti_enabled' => false]);

    $this->post('http://graderai.example.test/lti/force-refresh')
        ->assertForbidden()
        ->assertSee('invalid_lti_session');

    expect(session('ai_grader_lti_context'))->toBeNull();
});

it('rejects an installation whose organization is inactive', function (): void {
    $this->ltiEnvironment->organization->update(['is_active' => false]);

    $this->post('http://graderai.example.test/lti/login', [
        'iss' => LTI_SECURITY_ISSUER,
        'login_hint' => 'opaque',
        'client_id' => LTI_SECURITY_CLIENT_ID,
        'target_link_uri' => 'https://graderai.example.test/lti/launch',
        'lti_message_hint' => 'opaque',
        'lti_deployment_id' => LTI_SECURITY_DEPLOYMENT_ID,
    ])->assertForbidden()->assertSee('organization_inactive');
});

it('rejects an unknown installation during login', function (): void {
    $this->post('http://graderai.example.test/lti/login', [
        'iss' => LTI_SECURITY_ISSUER,
        'login_hint' => 'opaque',
        'client_id' => 'unknown-client',
        'target_link_uri' => 'https://graderai.example.test/lti/launch',
        'lti_message_hint' => 'opaque',
        'lti_deployment_id' => LTI_SECURITY_DEPLOYMENT_ID,
    ])->assertUnprocessable()->assertSee('unknown_installation');
});

it('allows an authorized role and rejects a learner role', function (): void {
    $flow = ltiSecurityLogin($this);
    $token = ltiSecurityToken(ltiSecurityClaims($flow['nonce'], [
        'https://purl.imsglobal.org/spec/lti/claim/roles' => [
            'http://purl.imsglobal.org/vocab/lis/v2/membership#Learner',
        ],
    ]), $this->ltiKey['private']);

    $this->post('http://graderai.example.test/lti/launch', ['state' => $flow['state'], 'id_token' => $token])
        ->assertForbidden()
        ->assertSee('role_not_allowed');
});

it('ignores externally supplied security context in a valid launch', function (): void {
    $flow = ltiSecurityLogin($this);
    $token = ltiSecurityToken(ltiSecurityClaims($flow['nonce']), $this->ltiKey['private']);

    $this->post('http://graderai.example.test/lti/launch', [
        'state' => $flow['state'],
        'id_token' => $token,
        'canvas_course_id' => '999',
        'canvas_user_id' => 'attacker',
        'roles' => ['Administrator'],
        'organization_id' => 999,
        'canvas_environment_id' => 999,
    ])->assertOk();

    $context = session('ai_grader_lti_context');
    expect($context['course_id'])->toBe('11')
        ->and($context['canvas_user_id'])->toBe('2001')
        ->and($context['organization_id'])->toBe($this->ltiEnvironment->organization_id)
        ->and($context['canvas_environment_id'])->toBe($this->ltiEnvironment->id);
});

it('does not expose the id_token in responses or logs', function (): void {
    Log::spy();
    $token = 'secret.invalid.id-token';

    $this->post('http://graderai.example.test/lti/launch', [
        'state' => 'unknown-state',
        'id_token' => $token,
    ])->assertBadRequest()->assertDontSee($token);

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => ! str_contains(json_encode([$message, $context]), $token)
    );
});

it('limits CSRF exemptions to login and launch', function (): void {
    $middleware = PreventRequestForgery::class;
    $routes = app('router')->getRoutes();

    expect(app(PreventRequestForgery::class)->getExcludedPaths())->toBe([])
        ->and($routes->getByName('lti.ai-grader.login')->excludedMiddleware())->toContain($middleware)
        ->and($routes->getByName('lti.ai-grader.launch')->excludedMiddleware())->toContain($middleware)
        ->and($routes->getByName('lti.ai-grader.force-refresh')->excludedMiddleware())->not->toContain($middleware);

    $this->withMiddleware($middleware)
        ->post('http://graderai.example.test/lti/login')
        ->assertUnprocessable();

    $this->withMiddleware($middleware)
        ->post('http://graderai.example.test/lti/launch')
        ->assertBadRequest();

});
