<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks a request as belonging to the partner-dashboard API so the global
 * exception renderer emits the contract envelope, forces JSON, and provides
 * lightweight CSRF hardening: session cookies are SameSite=Lax (not sent on
 * cross-site XHR), and on top of that any state-changing request must carry
 * an Origin/Referer from an allowed origin when one is present.
 */
class DashboardApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('dashboard_api', true);
        $request->headers->set('Accept', 'application/json');

        if (! $request->isMethodSafe() && ! $this->originAllowed($request)) {
            return response()->json([
                'error' => ['code' => 'FORBIDDEN_ORIGIN', 'message' => 'مصدر الطلب غير مسموح'],
            ], 403);
        }

        return $next($request);
    }

    private function originAllowed(Request $request): bool
    {
        $origin = $request->headers->get('Origin') ?? $request->headers->get('Referer');

        // Non-browser clients (no Origin header) are not CSRF-able — allow.
        if (! $origin) {
            return true;
        }

        $host = parse_url($origin, PHP_URL_HOST);
        if (! $host) {
            return false;
        }

        // Same registrable domain as the API (…mamsaa.com) or an explicitly
        // allowed CORS origin.
        if ($host === 'mamsaa.com' || str_ends_with($host, '.mamsaa.com')) {
            return true;
        }

        foreach (config('cors.allowed_origins', []) as $allowed) {
            if ($allowed !== '*' && parse_url($allowed, PHP_URL_HOST) === $host) {
                return true;
            }
        }

        foreach (config('cors.allowed_origins_patterns', []) as $pattern) {
            if (preg_match($pattern, rtrim((string) $origin, '/'))) {
                return true;
            }
        }

        return app()->isLocal();
    }
}
