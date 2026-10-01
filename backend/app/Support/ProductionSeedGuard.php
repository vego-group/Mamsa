<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Seeders that create accounts never run on production by accident.
 *
 * A leaked staging password (in the public repo from 2026-06-16) was rotated
 * on 2026-10-01, but rotating one value leaves the class open: DatabaseSeeder,
 * or any account seeder run alone with `--class`, would still mint privileged
 * accounts on production. `--force` only answers Laravel's "are you sure"
 * prompt; it does not get past this.
 *
 * The one way past is ALLOW_PRODUCTION_SEED=true in the environment, a
 * variable that exists for nothing else, so whoever sets it knows why.
 */
final class ProductionSeedGuard
{
    public static function assertAllowed(object|string $seeder): void
    {
        if (! app()->isProduction() || config('database.allow_production_seed') === true) {
            return;
        }

        $name = class_basename(is_object($seeder) ? $seeder::class : $seeder);

        throw new RuntimeException(
            "{$name} refuses to run on production: it creates accounts. "
            .'Set ALLOW_PRODUCTION_SEED=true in the environment (then config:cache) only if this is deliberate.'
        );
    }
}
