<?php

namespace Database\Seeders;

use App\Models\PartnerDetail;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Dev/testing accounts — one per role.
 *
 * Login via OTP: POST /api/v1/auth/request-otp  {"phone": "<number below>"}
 * The response includes debug_otp — click it in the browser to auto-fill.
 *
 * | Role       | Phone          | Name                | Email / Password login          |
 * |------------|----------------|---------------------|---------------------------------|
 * | SuperAdmin | +966500000000  | سوبر أدمن           | superadmin@mamsaa.sa / Password1|
 * | Admin      | +966500000001  | أحمد المدير         | admin@mamsaa.sa / Password1     |
 * | Individual | +966500000002  | محمد الشريك الفردي  | (OTP only)                      |
 * | Company    | +966500000003  | شركة الأفق للعقارات | (OTP only)                      |
 * | User       | +966500000004  | نورة المستخدمة      | (OTP only)                      |
 *
 * Back-office (Admin/SuperAdmin) sign in with email + password:
 *   POST /api/v1/auth/admin/login  {"email": "...", "password": "Password1"}
 */
class DevUsersSeeder extends Seeder
{
    public function run(): void
    {
        // 1. SuperAdmin — email + password login
        $superAdmin = User::updateOrCreate(
            ['phone' => '+966500000000'],
            [
                'name'      => 'سوبر أدمن',
                'email'     => 'superadmin@mamsaa.sa',
                'password'  => Hash::make('Password1'),
                'is_active' => true,
            ],
        );
        $superAdmin->syncRoles('SuperAdmin');

        // 2. Admin — email + password login
        $admin = User::updateOrCreate(
            ['phone' => '+966500000001'],
            [
                'name'      => 'أحمد المدير',
                'email'     => 'admin@mamsaa.sa',
                'password'  => Hash::make('Password1'),
                'is_active' => true,
            ],
        );
        $admin->syncRoles('Admin');

        // 3. Individual partner
        $individual = User::firstOrCreate(
            ['phone' => '+966500000002'],
            ['name' => 'محمد الشريك الفردي', 'email' => 'individual@mamsaa.sa', 'is_active' => true],
        );
        $individual->syncRoles('Individual');
        PartnerDetail::firstOrCreate(
            ['user_id' => $individual->id],
            ['type' => 'individual', 'national_id' => '1023456789'],
        );

        // 4. Company partner
        $company = User::firstOrCreate(
            ['phone' => '+966500000003'],
            ['name' => 'شركة الأفق للعقارات', 'email' => 'company@mamsaa.sa', 'is_active' => true],
        );
        $company->syncRoles('Company');
        PartnerDetail::firstOrCreate(
            ['user_id' => $company->id],
            ['type' => 'company', 'cr_number' => '1010123456'],
        );

        // 5. Regular user
        $user = User::firstOrCreate(
            ['phone' => '+966500000004'],
            ['name' => 'نورة المستخدمة', 'email' => 'user@mamsaa.sa', 'is_active' => true],
        );
        $user->syncRoles('User');
    }
}
