<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * One line per request that reached the application: when, from where, what,
 * how it ended, and how long it took.
 *
 * Added 2026-10-01 because a run of connection timeouts on staging could not
 * be diagnosed: the host keeps no access log on disk (hPanel only), so there
 * was nothing to read. Know its limit: a connection that never reaches PHP —
 * exactly a CONNECT_TIMEOUT — leaves no line. Its ABSENCE, beside lines from
 * the same minute, is the evidence that the failure was before the app.
 *
 * Exactly six fields — time, IP, path, status, duration, user — the scope the
 * owner approved for production on 2026-10-01. Widening it needs a new approval.
 * Deliberately NOT logged: the query string (signed document links carry their
 * signature there), the body, and every header. IPs are personal data — the
 * channel keeps ACCESS_LOG_DAYS days, 14 by default.
 *
 * How to read it, and the host's limits: docs/ops/access-log.md.
 *
 * Written at terminate(), after the response has gone, so it adds nothing to
 * the request's own time. Off unless ACCESS_LOG_ENABLED=true.
 */
final class AccessLog
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! config('logging.access_log.enabled')) {
            return;
        }

        try {
            $start = (float) $request->server('REQUEST_TIME_FLOAT', microtime(true));

            Log::channel('access')->info((string) json_encode([
                't' => now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
                'ip' => $request->ip(),
                'path' => '/'.ltrim($request->path(), '/'),
                'status' => $response->getStatusCode(),
                'ms' => (int) round((microtime(true) - $start) * 1000),
                'uid' => $request->user()?->getAuthIdentifier(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            // A log line must never turn a served request into an error.
            report($e);
        }
    }
}
