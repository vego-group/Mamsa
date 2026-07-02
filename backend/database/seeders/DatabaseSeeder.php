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
            ReviewsSeeder::class,              // ratings → powers the التقييم filter
            OffersSeeder::class,
            TestimonialsSeeder::class,
            DefaultMediaSeeder::class,         // point all images at the bundled default asset
            DemoAccountSeeder::class,          // cards + wallet + favourites + upcoming stay
            NotificationsSeeder::class,        // in-app bell content for admins + partners
        ]);
    }
}
