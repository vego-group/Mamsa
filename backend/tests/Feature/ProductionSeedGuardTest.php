<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DashboardTestPartnerSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevUsersSeeder;
use Database\Seeders\ReviewsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * No seeder creates accounts on production.
 *
 * Rotating the leaked staging password (2026-10-01) removed one credential.
 * This removes the class: DatabaseSeeder, and every seeder that creates users,
 * refuse to run when APP_ENV=production, `--force` included. The only way past
 * is ALLOW_PRODUCTION_SEED=true, set deliberately by someone who knows why.
 */
class ProductionSeedGuardTest extends TestCase
{
    use RefreshDatabase;

    /** Seeders that create accounts, each runnable alone with --class. */
    private const ACCOUNT_SEEDERS = [
        DatabaseSeeder::class,
        DevUsersSeeder::class,
        DashboardTestPartnerSeeder::class,
        ReviewsSeeder::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Roles create no accounts and stay runnable everywhere; the account
        // seeders need them to exist.
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function asProduction(bool $allow = false): void
    {
        $this->app['env'] = 'production';
        config()->set('database.allow_production_seed', $allow);
    }

    public function test_every_account_seeder_refuses_production_even_with_force(): void
    {
        $this->asProduction();

        foreach (self::ACCOUNT_SEEDERS as $seeder) {
            $users = User::count();

            try {
                $this->artisan('db:seed', ['--class' => $seeder, '--force' => true])->run();
                $this->fail("{$seeder} ran on production");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('ALLOW_PRODUCTION_SEED', $e->getMessage(), $seeder);
            }

            $this->assertSame($users, User::count(), "{$seeder} wrote accounts before refusing");
        }
    }

    public function test_the_refusal_names_the_seeder_and_the_way_past(): void
    {
        $this->asProduction();

        try {
            (new DevUsersSeeder)->run();
            $this->fail('DevUsersSeeder ran on production');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('DevUsersSeeder', $e->getMessage());
            $this->assertStringContainsString('ALLOW_PRODUCTION_SEED=true', $e->getMessage());
        }
    }

    public function test_the_explicit_variable_lets_it_through(): void
    {
        $this->asProduction(allow: true);

        (new DevUsersSeeder)->run();

        $this->assertTrue(User::where('email', 'admin@mamsaa.sa')->exists());
    }

    public function test_outside_production_seeding_is_unchanged(): void
    {
        $this->app['env'] = 'local';
        config()->set('database.allow_production_seed', false);

        (new DevUsersSeeder)->run();

        $this->assertTrue(User::where('email', 'admin@mamsaa.sa')->exists());
    }
}
