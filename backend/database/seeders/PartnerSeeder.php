<?php

namespace Database\Seeders;

use App\Models\PartnerDetail;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PartnerSeeder extends Seeder
{
    public function run(): void
    {
        // Individual partner
        $individual = User::firstOrCreate(
            ['phone' => '+966500000001'],
            [
                'name'      => 'شريك فردي تجريبي',
                'email'     => 'individual@mamsa.sa',
                'password'  => Hash::make('password'),
                'is_active' => true,
            ]
        );
        $individual->syncRoles('Individual');
        PartnerDetail::firstOrCreate(
            ['user_id' => $individual->id],
            ['type' => 'individual', 'national_id' => '1234567890']
        );

        // Company partner
        $company = User::firstOrCreate(
            ['phone' => '+966500000002'],
            [
                'name'      => 'شركة تجريبية',
                'email'     => 'company@mamsa.sa',
                'password'  => Hash::make('password'),
                'is_active' => true,
            ]
        );
        $company->syncRoles('Company');
        PartnerDetail::firstOrCreate(
            ['user_id' => $company->id],
            ['type' => 'company', 'cr_number' => '1010123456']
        );
    }
}
