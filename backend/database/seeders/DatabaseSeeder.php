<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Roles + permissions must be seeded first
        $this->call([
            RolesAndPermissionsSeeder::class,
            PartnerSeeder::class,
        ]);

        // SuperAdmin dev account
        $superAdmin = User::firstOrCreate(
            ['phone' => '+966500000000'],
            ['name' => 'Super Admin', 'is_active' => true, 'password' => Hash::make('password')],
        );
        $superAdmin->syncRoles('SuperAdmin');

        // Regular user dev account
        $user = User::firstOrCreate(
            ['phone' => '+966512345678'],
            ['name' => 'Test User', 'is_active' => true, 'password' => Hash::make('password')],
        );
        $user->syncRoles('User');
    }
}
