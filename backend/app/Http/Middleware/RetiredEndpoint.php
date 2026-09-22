<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * A route that is closed but still listening.
 *
 * The unit WRITE endpoints on the legacy Bearer surfaces (`/api/v1/partner`
 * and `/api/v1/admin`, the testvue console) are retired: every new rule —
 * permit uniqueness, expiry, per-apartment permits — would otherwise have to
 * be implemented and kept correct on four surfaces instead of two, and these
 * two have no client that needs them.
 *
 * Retired, not deleted, because nobody can prove a negative from this host: it
 * keeps no access logs (see docs/ops/HOST-NOTES-hostinger-shared.md), so
 * "no traffic" was never an observation, only an absence of evidence. So every
 * request that arrives is written down — route, user, method, ip, time — and
 * the caller is told plainly that the endpoint is gone. If the log turns out to
 * name a real client, removing this middleware from the route is the whole
 * revert.
 *
 * Reads stay open. The public guest API is untouched.
 */
class RetiredEndpoint
{
    public function handle(Request $request, Closure $next): Response
    {
        // The escape hatch, off by default: see config/units.php. Still logged,
        // so turning it back on does not turn the evidence off with it.
        if (config('units.legacy_unit_writes')) {
            Log::channel(config('logging.retired_channel', 'stack'))->info('retired endpoint served (legacy writes enabled)', [
                'route' => $request->route()?->getName() ?? $request->path(),
                'user_id' => $request->user()?->id,
            ]);

            return $next($request);
        }

        Log::channel(config('logging.retired_channel', 'stack'))->warning('retired endpoint called', [
            'route' => $request->route()?->getName() ?? $request->path(),
            'method' => $request->method(),
            'user_id' => $request->user()?->id,
            'roles' => $request->user()?->getRoleNames()->all(),
            'ip' => $request->ip(),
            'agent' => substr((string) $request->userAgent(), 0, 200),
            'at' => now()->toIso8601String(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'هذا المسار لم يعد مدعوماً. استخدم لوحة الشريك أو لوحة المشرف.',
            'code' => 'ENDPOINT_RETIRED',
        ], 410);
    }
}
