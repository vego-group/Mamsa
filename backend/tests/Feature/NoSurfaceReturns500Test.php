<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PartnerDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Every parameterless GET surface must answer without a server error.
 *
 * This exists because of a failure MODE, not a single bug. A controller using a
 * MySQL-only construct — DATE_FORMAT, DATEDIFF, TIMESTAMPDIFF, HAVING without
 * GROUP BY — throws under the sqlite test suite. If nobody wrote a test for
 * that endpoint, nothing ever calls it, nothing fails, and the suite reports
 * green while the surface is entirely unverified. Six controllers reached
 * production that way; each was found by hand, one at a time.
 *
 * Chasing the instances does not stop the next one. What stops it is making the
 * silence audible: this walks the router itself, so a NEW endpoint is covered
 * the moment it is registered, without anyone remembering to add it here.
 *
 * Deliberately weak assertion. It is not checking that a surface is correct —
 * only that it RUNS. A 401/403/404/422 is a fine answer; a 500 is not.
 */
class NoSurfaceReturns500Test extends TestCase
{
    use RefreshDatabase;

    /** Routes excluded from the walk, with the reason each is unreachable here. */
    private const SKIP = [
        'up',                    // framework health check, not ours
        'sanctum/csrf-cookie',   // returns a cookie, not a payload
    ];

    public function test_no_get_surface_returns_a_server_error(): void
    {
        foreach (['Individual', 'Company', 'Admin', 'SuperAdmin', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('SuperAdmin');

        $partner = User::factory()->create(['is_active' => true]);
        $partner->assignRole('Individual');
        $partner->partnerDetail()->create(['type' => 'individual', 'status' => PartnerDetail::STATUS_APPROVED]);

        $guest = User::factory()->create(['is_active' => true]);
        $guest->assignRole('User');

        $failures = [];
        $checked  = 0;

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = $route->uri();

            // Parameterised routes need fixture ids to be meaningful; the class
            // of fault this catches lives in list/stats endpoints, which take none.
            if (str_contains($uri, '{') || in_array($uri, self::SKIP, true)) {
                continue;
            }

            [$actor, $guard] = $this->actorFor($route, $admin, $partner, $guest);

            // NOT refreshApplication() between requests: the test database is
            // sqlite :memory:, which exists only for the life of its connection,
            // so re-bootstrapping the app destroys it mid-walk (a fatal, not a
            // failure). One app, many requests.
            $test = $actor ? $this->actingAs($actor, $guard) : $this;

            try {
                $status = $test->get('/'.ltrim($uri, '/'))->getStatusCode();
            } catch (\Throwable $e) {
                $failures[] = sprintf('%s → %s: %s', $uri, class_basename($e), $this->firstLine($e->getMessage()));

                continue;
            }

            $checked++;
            if (getenv('WALK_DEBUG')) { fwrite(STDERR, sprintf("    %-3d %s\n", $status, $uri)); }

            if ($status >= 500) {
                $failures[] = sprintf('%s → HTTP %d', $uri, $status);
            }
        }

        $this->assertNotEmpty($checked, 'the route walk found nothing to check — the filter is wrong');

        $this->assertSame([], $failures, sprintf(
            "%d of %d GET surfaces failed:\n  %s",
            count($failures),
            $checked + count($failures),
            implode("\n  ", $failures),
        ));
    }

    /**
     * The guard a route authenticates with, read off its own middleware, so a
     * new surface needs no entry here.
     *
     * @return array{0: ?User, 1: string}
     */
    private function actorFor(\Illuminate\Routing\Route $route, User $admin, User $partner, User $guest): array
    {
        foreach ($route->gatherMiddleware() as $m) {
            if (! is_string($m)) {
                continue;
            }

            // gatherMiddleware() yields the ALIAS ('auth:admin-panel'), not the
            // resolved class. Matching only the class name silently matched
            // nothing, every route fell through to the guest actor, and the
            // whole walk asserted 401s — passing while checking nothing.
            $guard = match (true) {
                str_starts_with($m, 'auth:') => explode(':', $m, 2)[1],
                str_starts_with($m, 'Illuminate\Auth\Middleware\Authenticate:') => explode(':', $m, 2)[1],
                default => null,
            };

            if ($guard === null) {
                continue;
            }

            return match ($guard) {
                'admin-panel' => [$admin, 'admin-panel'],
                'dashboard'   => [$partner, 'dashboard'],
                // One sanctum guard fronts admin, partner AND consumer routes,
                // so the prefix is what separates them. Sending a plain user at
                // the partner routes just earns a 403 — which is not a 500, so
                // the walk would have reported them "checked" while never
                // reaching a line of their controllers.
                'sanctum'     => match (true) {
                    str_starts_with($route->uri(), 'api/v1/admin')   => [$admin, 'sanctum'],
                    str_starts_with($route->uri(), 'api/v1/partner') => [$partner, 'sanctum'],
                    default                                          => [$guest, 'sanctum'],
                },
                default       => [null, 'web'],
            };
        }

        return [null, 'web'];
    }

    private function firstLine(string $s): string
    {
        return trim(explode("\n", $s)[0]);
    }
}
