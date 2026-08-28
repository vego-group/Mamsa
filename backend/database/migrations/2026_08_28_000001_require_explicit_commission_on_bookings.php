<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the DEFAULT from the money-split columns so a booking cannot be written
 * without one.
 *
 * Both columns were already NOT NULL — what let an unfrozen row exist was the
 * `DEFAULT 0`, which silently supplied a value for any INSERT that forgot to.
 * A row like that reads as "commission of zero", indistinguishable from a
 * booking that legitimately owes none, and every report had to guess between
 * them.
 *
 * With no default, MySQL rejects the INSERT outright. The guarantee is enforced
 * by the database on every path, in production, without anyone watching — which
 * is exactly what a read-time fallback could never do.
 *
 * The trade is that any direct `Booking::create()` must now name both values.
 * That is a gain: a test states the rate it is exercising instead of inheriting
 * a silent zero.
 *
 * Backfill first, so nothing is left holding an implicit value.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Anything still on the old implicit zero gets the rate it was taken
        // under, so dropping the default cannot strand a row mid-migration.
        DB::table('bookings')
            ->where('commission_rate', 0)
            ->where('commission_amount', 0)
            ->whereNotNull('subtotal')
            ->where('subtotal', '>', 0)
            ->update([
                'commission_rate'   => DB::raw((string) \App\Models\Booking::LEGACY_COMMISSION_RATE),
                'commission_amount' => DB::raw('ROUND(subtotal * '.\App\Models\Booking::LEGACY_COMMISSION_RATE.', 2)'),
            ]);

        // SQLite (tests) cannot ALTER a column's default and does not enforce
        // one the way MySQL does; the application-level guarantee is identical
        // either way, so this is a no-op there rather than a failure.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE bookings ALTER COLUMN commission_rate DROP DEFAULT');
        DB::statement('ALTER TABLE bookings ALTER COLUMN commission_amount DROP DEFAULT');
        DB::statement('ALTER TABLE bookings ALTER COLUMN partner_share DROP DEFAULT');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE bookings ALTER COLUMN commission_rate SET DEFAULT 0');
        DB::statement('ALTER TABLE bookings ALTER COLUMN commission_amount SET DEFAULT 0');
        DB::statement('ALTER TABLE bookings ALTER COLUMN partner_share SET DEFAULT 0');
    }
};
