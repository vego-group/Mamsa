<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('roles')->insert([
            ['role_name' => 'User'],
            ['role_name' => 'Partner'],
            ['role_name' => 'Admin'],
            ['role_name' => 'Super admin'],
        ]);
    }
}
