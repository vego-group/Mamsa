<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Capture the API's SHAPE, so a contract change cannot ship unnoticed.
 *
 * The deploy procedure has always included a before/after comparison of
 * endpoint output, and it has slipped repeatedly — not from unwillingness but
 * because it lived in someone's memory rather than in a step that produces
 * something. A missing file is visible; a forgotten intention is not.
 *
 * So this writes an artifact. Run it before a deploy and after one, diff the
 * two, and attach the result. If there is no file, the procedure did not
 * happen — and that is the point.
 *
 * It records KEY SETS and status codes, never values. Two reasons: the shape is
 * what the contract is about, and a snapshot that carried real payloads would
 * be a file full of partner addresses and permit numbers being emailed around.
 *
 *   php artisan api:snapshot --out=before.json
 *   # …deploy…
 *   php artisan api:snapshot --out=after.json
 *   php artisan api:snapshot --diff=before.json --against=after.json
 */
class ApiSnapshot extends Command
{
    protected $signature = 'api:snapshot
        {--out= : Write the snapshot to this path}
        {--diff= : Compare this snapshot…}
        {--against= : …against this one, and print what changed}';

    protected $description = 'Record the API surface (routes + public response shapes) for before/after comparison';

    /** Not ours, or not a payload. */
    private const SKIP = ['up', 'sanctum/csrf-cookie'];

    public function handle(): int
    {
        if ($this->option('diff')) {
            return $this->renderDiff();
        }

        $snapshot = [
            'taken_at' => now()->toIso8601String(),
            'env'      => app()->environment(),
            'routes'   => $this->routes(),
            'shapes'   => $this->publicShapes(),
        ];

        $json = (string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($out = $this->option('out')) {
            file_put_contents($out, $json);
            $this->info("Snapshot written: {$out}");
            $this->line('  routes: '.count($snapshot['routes']).'   public shapes: '.count($snapshot['shapes']));

            return self::SUCCESS;
        }

        $this->line($json);

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function routes(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $routes[] = $method.' '.$route->uri();
            }
        }

        sort($routes);

        return array_values(array_unique($routes));
    }

    /**
     * Response key sets for every parameterless, unauthenticated GET.
     *
     * Walking the router rather than listing endpoints by hand means a NEW
     * public surface is covered the moment it is registered — the same reason
     * NoSurfaceReturns500Test walks it instead of enumerating.
     *
     * @return array<string, array<string, mixed>>
     */
    private function publicShapes(): array
    {
        $kernel = app(Kernel::class);
        $shapes = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            if (str_contains($route->uri(), '{') || in_array($route->uri(), self::SKIP, true)) {
                continue;
            }
            // Anything behind a guard needs a credential this command has no
            // business holding. Shapes for those are covered by the suite.
            if ($this->isGuarded($route->gatherMiddleware())) {
                continue;
            }

            $response = $kernel->handle(Request::create('/'.ltrim($route->uri(), '/'), 'GET'));
            $body     = json_decode((string) $response->getContent(), true);

            $shapes['GET /'.ltrim($route->uri(), '/')] = [
                'status' => $response->getStatusCode(),
                'keys'   => $this->keysOf($body),
            ];
        }

        ksort($shapes);

        return $shapes;
    }

    /** @param array<int, mixed> $middleware */
    private function isGuarded(array $middleware): bool
    {
        foreach ($middleware as $m) {
            if (! is_string($m)) {
                continue;
            }
            // The alias, not the resolved class — gatherMiddleware() returns
            // `auth:sanctum`, and matching only the class name is how a guard
            // check silently passes over every protected route.
            if (str_starts_with($m, 'auth:')
                || str_starts_with($m, 'Illuminate\Auth\Middleware\Authenticate:')
                || $m === 'auth') {
                return true;
            }
        }

        return false;
    }

    /**
     * The key set, descended one level into a `data` list so a collection
     * endpoint reports its ROW shape rather than just {data, links, meta}.
     *
     * @return array<int, string>
     */
    private function keysOf(mixed $body): array
    {
        if (! is_array($body)) {
            return [];
        }

        $keys = array_keys($body);

        if (isset($body['data']) && is_array($body['data'])) {
            $first = $body['data'][0] ?? $body['data'];

            if (is_array($first)) {
                foreach (array_keys($first) as $k) {
                    $keys[] = 'data[].'.$k;
                }
            }
        }

        $keys = array_values(array_unique(array_map('strval', $keys)));
        sort($keys);

        return $keys;
    }

    private function renderDiff(): int
    {
        $before = json_decode((string) @file_get_contents((string) $this->option('diff')), true);
        $after  = json_decode((string) @file_get_contents((string) $this->option('against')), true);

        if (! is_array($before) || ! is_array($after)) {
            $this->error('Both --diff and --against must point at snapshot files.');

            return self::FAILURE;
        }

        $changed = false;

        $addedRoutes   = array_diff($after['routes'] ?? [], $before['routes'] ?? []);
        $removedRoutes = array_diff($before['routes'] ?? [], $after['routes'] ?? []);

        foreach (['ADDED route' => $addedRoutes, 'REMOVED route' => $removedRoutes] as $label => $set) {
            foreach ($set as $r) {
                $this->line(($label === 'ADDED route' ? '  + ' : '  - ')."{$label}: {$r}");
                $changed = true;
            }
        }

        foreach ($after['shapes'] ?? [] as $endpoint => $shape) {
            $was = $before['shapes'][$endpoint] ?? null;

            if ($was === null) {
                $this->line("  + NEW surface: {$endpoint}");
                $changed = true;

                continue;
            }

            foreach (array_diff($shape['keys'], $was['keys']) as $k) {
                $this->line("  + KEY {$endpoint}: {$k}");
                $changed = true;
            }
            foreach (array_diff($was['keys'], $shape['keys']) as $k) {
                $this->line("  - KEY {$endpoint}: {$k}");
                $changed = true;
            }
            if ($shape['status'] !== $was['status']) {
                $this->line("  ! STATUS {$endpoint}: {$was['status']} → {$shape['status']}");
                $changed = true;
            }
        }

        if (! $changed) {
            $this->info('✓ No contract change: routes and public response shapes are identical.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('This is a contract change. It goes to the frontend BEFORE it ships.');

        // Non-zero on purpose: a deploy script can gate on this.
        return self::FAILURE;
    }
}
