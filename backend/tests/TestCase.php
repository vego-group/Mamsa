<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run against anything but an in-memory SQLite database.
     *
     * This check runs BEFORE parent::setUp() on purpose. Laravel boots the
     * application and fires the RefreshDatabase hook inside parent::setUp(),
     * so a guard placed after it would announce the wrong database only once
     * `migrate:fresh` had already dropped every table. By then the warning is
     * an obituary.
     *
     * It reads the superglobals directly rather than config(), for the same
     * reason: there is no application yet. The lookup order mirrors Laravel's
     * own env repository — $_SERVER first, then $_ENV, then getenv() — so what
     * this sees is exactly what the framework would have resolved.
     *
     * Twice now (2026-07-28, 2026-09-06) the dev MySQL has been wiped by a test
     * run that believed phpunit.xml was protecting it. Configuration that is
     * merely correct can be defeated by an environment variable; this cannot.
     */
    protected function setUp(): void
    {
        $connection = $this->rawEnv('DB_CONNECTION');
        $database   = $this->rawEnv('DB_DATABASE');

        // The cache is shared state too. When it resolved to the container's
        // real Redis, rate-limit counters persisted between tests and between
        // runs, and suites failed with 429s that reproduced nowhere else.
        $cache = $this->rawEnv('CACHE_STORE');

        if ($cache !== null && $cache !== 'array') {
            throw new RuntimeException(
                "Refusing to run tests: CACHE_STORE resolved to [{$cache}], not array.\n"
                .'Tests would share one cache — rate limiters and all — across runs.'
            );
        }

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(
                'Refusing to run tests: the resolved database is '
                ."[{$connection}] / [{$database}], not sqlite / :memory:.\n"
                ."RefreshDatabase would drop every table in it.\n\n"
                ."Inside Docker, pass the overrides explicitly:\n"
                ."  docker compose exec -T -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: \\\n"
                ."    -e DB_HOST=127.0.0.1 backend php artisan test\n"
            );
        }

        parent::setUp();
    }

    /** Read an env value the way Laravel's repository would, before it exists. */
    private function rawEnv(string $key): ?string
    {
        foreach ([$_SERVER, $_ENV] as $source) {
            if (isset($source[$key]) && $source[$key] !== '') {
                return (string) $source[$key];
            }
        }

        $value = getenv($key);

        return $value === false || $value === '' ? null : (string) $value;
    }
}
