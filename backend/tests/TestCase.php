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
        // Every service the container defines and tests must not touch. Fixing
        // only the one that hurt is what let the cache leak survive the
        // database fix; the rule is the class, not the instance.
        $required = [
            'DB_CONNECTION'        => 'sqlite',
            'DB_DATABASE'          => ':memory:',
            'CACHE_STORE'          => 'array',
            'SESSION_DRIVER'       => 'array',
            'QUEUE_CONNECTION'     => 'sync',
            'MAIL_MAILER'          => 'array',
            'BROADCAST_CONNECTION' => 'null',
        ];

        foreach ($required as $key => $expected) {
            $actual = $this->rawEnv($key);

            // Absent is fine: outside Docker the container defines nothing and
            // phpunit.xml's own value applies. Present-and-wrong is not.
            if ($actual !== null && $actual !== $expected) {
                throw new RuntimeException(
                    "Refusing to run tests: {$key} resolved to [{$actual}], not [{$expected}].\n"
                    ."Tests would reach shared infrastructure — a real database, cache, queue or mail\n"
                    ."transport — instead of an isolated one.\n\n"
                    ."phpunit.xml must declare it with <server>, not <env>: PHPUnit's <env force=\"true\">\n"
                    ."writes \$_ENV and putenv() but NOT \$_SERVER, and Laravel reads \$_SERVER first.\n"
                );
            }
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
