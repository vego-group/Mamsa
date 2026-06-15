<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // ── Permissions ──────────────────────────────────────────────

        $permissions = [
            // Public / browsing
            'units.browse',
            'units.view',
            'units.check-availability',

            // Authenticated user (renter)
            'profile.view',
            'profile.update',
            'bookings.create',
            'bookings.view-own',
            'bookings.cancel-own',
            'payments.pay',
            'payments.view-own',
            'reviews.create',

            // Partner (individual / company)
            'partner.dashboard',
            'partner.profile.update',
            'partner.units.create',
            'partner.units.view-own',
            'partner.units.update-own',
            'partner.units.delete-own',
            'partner.units.submit',
            'partner.units.upload-images',
            'partner.bookings.view',

            // Admin
            'admin.dashboard',
            'admin.users.view',
            'admin.users.create',
            'admin.users.update-status',
            'admin.users.delete',
            'admin.units.view',
            'admin.bookings.view',
            'admin.requests.view',
            'admin.requests.approve',
            'admin.requests.reject',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // ── Roles + Permission Assignment ─────────────────────────────

        // User (renter)
        $user = Role::firstOrCreate(['name' => 'User', 'guard_name' => 'web']);
        $user->syncPermissions([
            'units.browse', 'units.view', 'units.check-availability',
            'profile.view', 'profile.update',
            'bookings.create', 'bookings.view-own', 'bookings.cancel-own',
            'payments.pay', 'payments.view-own',
            'reviews.create',
        ]);

        // Individual partner
        $individual = Role::firstOrCreate(['name' => 'Individual', 'guard_name' => 'web']);
        $individual->syncPermissions([
            // inherits user permissions
            'units.browse', 'units.view', 'units.check-availability',
            'profile.view', 'profile.update',
            'bookings.create', 'bookings.view-own', 'bookings.cancel-own',
            'payments.pay', 'payments.view-own',
            'reviews.create',
            // partner-specific
            'partner.dashboard',
            'partner.profile.update',
            'partner.units.create', 'partner.units.view-own',
            'partner.units.update-own', 'partner.units.delete-own',
            'partner.units.submit', 'partner.units.upload-images',
            'partner.bookings.view',
        ]);

        // Company partner (same permissions as Individual)
        $company = Role::firstOrCreate(['name' => 'Company', 'guard_name' => 'web']);
        $company->syncPermissions($individual->permissions->pluck('name')->toArray());

        // Admin
        $admin = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $admin->syncPermissions([
            'units.browse', 'units.view', 'units.check-availability',
            'profile.view', 'profile.update',
            'admin.dashboard',
            'admin.users.view', 'admin.users.create',
            'admin.users.update-status', 'admin.users.delete',
            'admin.units.view',
            'admin.bookings.view',
            'admin.requests.view', 'admin.requests.approve', 'admin.requests.reject',
        ]);

        // SuperAdmin — wildcard (*) covers everything
        $superAdmin = Role::firstOrCreate(['name' => 'SuperAdmin', 'guard_name' => 'web']);
        $superAdmin->syncPermissions(Permission::all());
    }
}
