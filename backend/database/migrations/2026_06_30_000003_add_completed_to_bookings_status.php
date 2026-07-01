<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add 'completed' to the bookings.status enum so the checkout-completion job
 * (bookings:complete) can transition Confirmed → Completed after the stay ends.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE bookings MODIFY status ENUM('pending','confirmed','cancelled','completed') NOT NULL DEFAULT 'pending'");
        }
        // SQLite stores enums as TEXT (no enum constraint to alter) — nothing to do.
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Park any completed rows back to confirmed before shrinking the enum.
            DB::table('bookings')->where('status', 'completed')->update(['status' => 'confirmed']);
            DB::statement("ALTER TABLE bookings MODIFY status ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending'");
        }
    }
};
