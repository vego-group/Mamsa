<?php

declare(strict_types=1);

/**
 * CORS for the decoupled SPA frontend. Token (Bearer) auth is used, so cookies
 * are not required cross-origin (supports_credentials = false). Lock the allowed
 * origins down to the deployed frontend(s) via CORS_ALLOWED_ORIGINS.
 */
return [

    /*
     * Every path, so an UNMATCHED route still answers with CORS headers.
     *
     * With an explicit list, a request to a path that has no route skipped this
     * middleware entirely and the 404 came back bare — so the browser blocked
     * it and reported a CORS failure instead of the 404. A missing endpoint then
     * looks like an infrastructure fault, which is the most expensive kind of
     * misdiagnosis to hand a client team.
     *
     * Widening this is not a widening of access: `allowed_origins` below is the
     * control, and it is an explicit allowlist. The surfaces are api/*,
     * sanctum/csrf-cookie, the root-mounted partner dashboard (/me, /units,
     * /wallet, /payouts, …) and the admin BFF under /admin/*.
     */
    'paths' => ['*'],

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

    // Cookie-session partner-dashboard requires credentialed CORS. Note:
    // browsers reject credentials with a '*' origin — keep origins explicit
    // wherever this is true (server env sets it).
    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', false),
];
