<?php

return [
    'domain' => env('GRADERAI_DOMAIN'),
    'url' => env('GRADERAI_URL'),
    'lti' => [
        'state_ttl_seconds' => (int) env('GRADERAI_LTI_STATE_TTL_SECONDS', 600),
        'session_ttl_minutes' => (int) env('GRADERAI_LTI_SESSION_TTL_MINUTES', 120),
        'clock_skew_seconds' => (int) env('GRADERAI_LTI_CLOCK_SKEW_SECONDS', 60),
        'max_token_age_seconds' => (int) env('GRADERAI_LTI_MAX_TOKEN_AGE_SECONDS', 600),
        'jwks_cache_seconds' => (int) env('GRADERAI_LTI_JWKS_CACHE_SECONDS', 300),
        'signing_key_id' => env('GRADERAI_LTI_SIGNING_KEY_ID'),
        'signing_private_key' => env('GRADERAI_LTI_SIGNING_PRIVATE_KEY'),
    ],
];
