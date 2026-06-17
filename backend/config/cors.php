<?php

declare(strict_types=1);

/**
 * CORS for the decoupled SPA frontend. Token (Bearer) auth is used, so cookies
 * are not required cross-origin (supports_credentials = false). Lock the allowed
 * origins down to the deployed frontend(s) via CORS_ALLOWED_ORIGINS.
 */
return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Comma-separated list in env, e.g.
    //   CORS_ALLOWED_ORIGINS=https://mamsa.vercel.app,https://mamsa.com
    'allowed_origins' => array_filter(
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', '*')))
    ),

    // Allow Vercel preview deployments (mamsa-*.vercel.app) without listing each.
    'allowed_origins_patterns' => array_filter(
        explode(',', (string) env('CORS_ALLOWED_ORIGINS_PATTERNS', ''))
    ),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => false,
];
