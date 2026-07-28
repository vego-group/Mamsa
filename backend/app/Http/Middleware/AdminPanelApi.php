<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks a request as belonging to the admin-panel (Next.js) BFF so the global
 * exception renderer emits its flat { message, code } envelope and forces JSON.
 *
 * CSRF hardening mirrors DashboardApi: session cookies are SameSite=Lax (never
 * sent on cross-site XHR), and any unsafe request must carry an Origin/Referer
 * from an allowed origin when one is present. Admin and partner cookie sessions
 * use separate guards, so they coexist in the same browser without collision.
 */
class AdminPanelApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('admin_panel_api', true);
        $request->headers->set('Accept', 'application/json');

        if (! $request->isMethodSafe() && ! $this->originAllowed($request)) {
            return response()->json([
                'message' => 'مصدر الطلب غير مسموح',
                'code'    => 'FORBIDDEN_ORIGIN',
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
        // allowed CORS origin / pattern.
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
