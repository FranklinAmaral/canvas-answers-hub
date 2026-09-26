<?php

declare(strict_types=1);

namespace App\Services\AiGrader;

class AiGraderLtiConfigService
{
    /**
     * @return array{login: string, launch: string, jwks: string, config: string}
     */
    public function urls(): array
    {
        return [
            'login' => $this->graderUrl('/lti/login'),
            'launch' => $this->graderUrl('/lti/launch'),
            'jwks' => $this->graderUrl('/lti/jwks'),
            'config' => $this->graderUrl('/lti/config'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $urls = $this->urls();
        $customFields = [
            'canvas_course_id' => '$Canvas.course.id',
            'canvas_user_id' => '$Canvas.user.id',
            'canvas_user_login_id' => '$Canvas.user.loginId',
            'canvas_membership_roles' => '$Canvas.membership.roles',
            'canvas_account_id' => '$Canvas.account.id',
        ];

        $payload = [
            'title' => 'GraderAI',
            'description' => 'GraderAI LTI launch for Canvas.',
            'oidc_initiation_url' => $urls['login'],
            'target_link_uri' => $urls['launch'],
            'config_url' => $urls['config'],
            'custom_fields' => $customFields,
            'extensions' => [
                [
                    'platform' => 'canvas.instructure.com',
                    'settings' => [
                        'text' => 'GraderAI',
                        'custom_fields' => $customFields,
                        'placements' => [
                            [
                                'placement' => 'course_navigation',
                                'message_type' => 'LtiResourceLinkRequest',
                                'target_link_uri' => $urls['launch'],
                                'text' => 'GraderAI',
                                'enabled' => true,
                                'visibility' => 'admins',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        if ($this->publicJwks() !== null) {
            $payload['public_jwk_url'] = $urls['jwks'];
        }

        return $payload;
    }

    /**
     * @return array{keys: array<int, array<string, string>>}|null
     */
    public function publicJwks(): ?array
    {
        $privateKey = config('graderai.lti.signing_private_key');
        $keyId = config('graderai.lti.signing_key_id');

        if (! is_string($privateKey) || trim($privateKey) === '' || ! is_string($keyId) || trim($keyId) === '') {
            return null;
        }

        $privateKey = str_replace('\\n', "\n", trim($privateKey));
        $key = openssl_pkey_get_private($privateKey);

        if ($key === false) {
            return null;
        }

        $details = openssl_pkey_get_details($key);
        $rsa = is_array($details) && is_array($details['rsa'] ?? null) ? $details['rsa'] : null;

        if (! is_array($rsa) || ! is_string($rsa['n'] ?? null) || ! is_string($rsa['e'] ?? null)) {
            return null;
        }

        return [
            'keys' => [[
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => trim($keyId),
                'n' => $this->base64UrlEncode($rsa['n']),
                'e' => $this->base64UrlEncode($rsa['e']),
            ]],
        ];
    }

    public function prettyJson(): string
    {
        $json = json_encode($this->payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : '{}';
    }

    private function graderUrl(string $path): string
    {
        $graderAiUrl = config('graderai.url');

        if (! is_string($graderAiUrl) || trim($graderAiUrl) === '') {
            throw new \LogicException('GRADERAI_URL must be configured.');
        }

        return rtrim($graderAiUrl, '/').'/'.ltrim($path, '/');
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
