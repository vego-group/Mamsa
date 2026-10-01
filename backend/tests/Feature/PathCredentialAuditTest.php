<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * No route reachable without signing in may carry an access key in its PATH
 * unless someone has looked at it and said so.
 *
 * Why: the access log writes every path it serves. The query string is never
 * logged, but a credential in the path is: the iCal feed wrote its calendar
 * token there until 2026-10-01. That one is masked now (any `{token}` route
 * parameter, plus the calendar pattern), but a future `{invite}` or `{reset}`
 * would not be.
 *
 * So this walks the router and lists every route that has a path parameter and
 * no `Authenticate` guard, signed routes included, and requires that list to
 * equal the reviewed one below. A new public or signed route with a path
 * parameter fails here until someone answers, for that parameter: does knowing
 * this value reach something without signing in? If yes, name it `{token}` so
 * the access-log mask covers it. Then add it here and to the audit table in
 * docs/ops/access-log.md.
 */
class PathCredentialAuditTest extends TestCase
{
    /**
     * Route name => its path parameters, each reviewed 2026-10-01 (250 routes,
     * docs/ops/access-log.md § Credential-in-path audit).
     */
    private const REVIEWED = [
        // Signed: the key is the `signature` in the query, never logged.
        'complaints.attachment' => ['attachment'],
        'documents.show' => ['upload'],
        'pd.uploads.receive' => ['upload'],
        // Laravel's local-disk route: ServeFile rejects an invalid query signature.
        'storage.local' => ['path'],
        'storage.local.upload' => ['path'],
        // Public listing: id, listing_id or code, all published.
        'api.units.show' => ['unit'],
        'api.units.availability' => ['unit'],
        'api.units.blocked-dates' => ['unit'],
        'api.units.reviews' => ['unit'],
        // THE credential in a path. Masked by AccessLog (`{token}` + pattern).
        'api.calendar.export' => ['token'],
    ];

    public function test_every_path_parameter_reachable_without_sign_in_has_been_reviewed(): void
    {
        $found = $this->unauthenticatedWithPathParameters();

        $this->assertSame(
            self::REVIEWED,
            $found,
            "A route reachable without signing in has a path parameter nobody has reviewed.\n"
            ."Ask of each one: does knowing this value reach something without signing in?\n"
            ."If yes, name it {token} so the access log masks it. Then update REVIEWED here\n"
            .'and the audit table in docs/ops/access-log.md.',
        );
    }

    public function test_the_only_credential_parameter_is_named_token(): void
    {
        // The access-log mask keys on the NAME `token`. A credential under any
        // other name would be logged in full.
        $this->assertSame(['api.calendar.export' => ['token']], array_filter(
            self::REVIEWED,
            fn (array $params) => in_array('token', $params, true),
        ));
    }

    public function test_the_walk_sees_a_new_public_route_and_ignores_a_signed_in_one(): void
    {
        // Proves the detector fires. A walk that matched nothing would pass the
        // first test only if REVIEWED were empty, but make that impossible to
        // miss anyway.
        Route::get('/__audit-probe/{invite}', fn () => 'x')->name('audit.probe.public');
        Route::get('/__audit-probe-auth/{invite}', fn () => 'x')->middleware('auth:sanctum')->name('audit.probe.auth');

        $found = $this->unauthenticatedWithPathParameters();

        $this->assertSame(['invite'], $found['audit.probe.public'] ?? null);
        $this->assertArrayNotHasKey('audit.probe.auth', $found);
        $this->assertSame(['token'], $found['api.calendar.export'] ?? null);
    }

    /** @return array<string, list<string>> route name => path parameters, sorted by name */
    private function unauthenticatedWithPathParameters(): array
    {
        /** @var Router $router */
        $router = app(Router::class);
        $router->getRoutes()->refreshNameLookups();
        $found = [];

        foreach ($router->getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $params = $route->parameterNames();
            if ($params === [] || $this->requiresSignIn($router, $route)) {
                continue;
            }

            $found[$route->getName() ?? $route->methods()[0].' '.$route->uri()] = $params;
        }

        $reviewedOrder = array_keys(self::REVIEWED);
        uksort($found, function (string $a, string $b) use ($reviewedOrder): int {
            $ia = array_search($a, $reviewedOrder, true);
            $ib = array_search($b, $reviewedOrder, true);

            return ($ia === false ? PHP_INT_MAX : $ia) <=> ($ib === false ? PHP_INT_MAX : $ib) ?: strcmp($a, $b);
        });

        return $found;
    }

    /**
     * Resolved through the router, so aliases ('auth:sanctum') and groups
     * become classes. Matching aliases alone has silently matched nothing
     * here before; see NoSurfaceReturns500Test::actorFor().
     */
    private function requiresSignIn(Router $router, RoutingRoute $route): bool
    {
        foreach ($router->gatherRouteMiddleware($route) as $m) {
            if (is_string($m) && str_starts_with($m, Authenticate::class)) {
                return true;
            }
        }

        return false;
    }
}
