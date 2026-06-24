<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Booking-level cancellation data.
 * - cancellation_snapshot: frozen policy at payment time (FR-036, NFR-014).
 *   Stored as JSON on the booking ("JSON أو جدول مرتبط بالحجز" — SRS 1.1).
 * - status gains 'completed' so the SRS 5.2 lifecycle is representable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->json('cancellation_snapshot')->nullable()->after('status');
            $table->timestamp('cancelled_at')->nullable()->after('cancellation_snapshot');
        });

        // Widen the status enum to include 'completed' (Confirmed → Completed).
        // MySQL-only: sqlite stores enums as TEXT with no constraint, so the new
        // value already works there (keeps the test suite green).
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE bookings MODIFY status ".
                "ENUM('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending'"
            );
        }
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['cancellation_snapshot', 'cancelled_at']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE bookings MODIFY status ".
                "ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending'"
            );
        }
    }
};
