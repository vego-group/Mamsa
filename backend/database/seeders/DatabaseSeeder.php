<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            CancellationPolicySeeder::class,   // before units so they can reference a policy
            DevUsersSeeder::class,
            SampleUnitsSeeder::class,
            OffersSeeder::class,
        ]);
    }
}
