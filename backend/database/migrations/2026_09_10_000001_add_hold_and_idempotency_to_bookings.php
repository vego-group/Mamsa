<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three columns the multi-unit contract asked for (v5 §add-b, §add-d, §add-e).
 *
 * `hold_expires_at` is the one that changes behaviour. An unpaid booking holds
 * its nights, and today it keeps holding them until `bookings:expire-pending`
 * runs — a 60-minute age on a job that fires every 15, so up to 75 minutes of
 * nights locked to an abandoned checkout. With an expiry stamped on the row,
 * the availability predicate stops counting it the moment the clock passes,
 * and the job is left doing what it should: tidying the status, not gating
 * whether anyone else can book.
 *
 * NULL means "no expiry" and keeps holding, which is what every booking made
 * before this migration is. Backfilling them with a past timestamp would
 * release live checkouts the instant this deploys.
 *
 * `idempotency_key` is UNIQUE so a replayed create collides at the engine
 * rather than relying on a read-then-write that two concurrent requests can
 * both pass. `units_count` exists now, locked to 1, so opening multi-unit
 * booking later does not change the shape of the API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedTinyInteger('units_count')->default(1)->after('unit_id');
            $table->timestamp('hold_expires_at')->nullable()->after('status');
            $table->string('idempotency_key', 64)->nullable()->unique()->after('hold_expires_at');

            // The expiry sweep reads exactly this pair.
            $table->index(['status', 'hold_expires_at'], 'idx_bookings_hold_expiry');
        });

        // sqlite cannot ALTER in a CHECK, and the test suite runs on it. The
        // constraint is a guard against a stray write, not the enforcement the
        // application relies on — that is a validation rule returning 422.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE bookings ADD CONSTRAINT chk_bookings_units_count CHECK (units_count = 1)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE bookings DROP CONSTRAINT chk_bookings_units_count');
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('idx_bookings_hold_expiry');
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['units_count', 'hold_expires_at', 'idempotency_key']);
        });
    }
};
