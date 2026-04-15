<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\AdminDetail;
use App\Models\Role; // 👈 مودل roles عندك
use Illuminate\Support\Facades\DB;

class AdminUsersSeeder extends Seeder
{
    public function run(): void
    {
        /* ===============================
           Roles (بدون guard_name)
        ============================== */
        $superRole = Role::firstOrCreate([
            'name' => 'SuperAdmin',
        ]);

        $AdminRole = Role::firstOrCreate([
            'name' => 'Admin',
        ]);

        /* ===============================
           Super Admin
        ============================== */
        $super = User::firstOrCreate(
            ['phone' => '0500000000'],
            [
                'name'      => 'SuperAdmin',
                'email'     => 'super@mamsa.test',
                'is_active' => 1,
            ]
        );

        // ربط الدور (pivot)
        DB::table('user_roles')->updateOrInsert([
            'user_id' => $super->id,
            'role_id' => $superRole->id,
        ]);

        AdminDetail::firstOrCreate(
            ['user_id' => $super->id],
            [
                'type' => 'individual',
            ]
        );

        /* ===============================
           Admin – Individual
        ============================== */
        $AdminIndividual = User::firstOrCreate(
            ['phone' => '0501111111'],
            [
                'name'      => 'Admin Individual',
                'email'     => 'Admin_individual@mamsa.test',
                'is_active' => 1,
            ]
        );

        DB::table('user_roles')->updateOrInsert([
            'user_id' => $AdminIndividual->id,
            'role_id' => $AdminRole->id,
        ]);

        AdminDetail::firstOrCreate(
            ['user_id' => $AdminIndividual->id],
            [
                'type'        => 'individual',
                'national_id' => '1010101010',
            ]
        );

        /* ===============================
           Admin – Company
        ============================== */
        $AdminCompany = User::firstOrCreate(
            ['phone' => '0502222222'],
            [
                'name'      => 'Admin Company',
                'email'     => 'Admin_company@mamsa.test',
                'is_active' => 1,
            ]
        );

        DB::table('user_roles')->updateOrInsert([
            'user_id' => $AdminCompany->id,
            'role_id' => $AdminRole->id,
        ]);

        AdminDetail::firstOrCreate(
            ['user_id' => $AdminCompany->id],
            [
                'type'       => 'company',
                'cr_number'  => '2050123456',
            ]
        );
    }
}