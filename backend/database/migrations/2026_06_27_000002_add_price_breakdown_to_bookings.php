<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Frozen price breakdown for the booking-detail page (ملخص السعر).
 * `total_amount` already exists; these columns itemise how it was reached so
 * the figure is auditable and the breakdown survives later rate changes.
 * Backfills legacy rows: nightly_rate/subtotal from total, fees at 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('nightly_rate', 10, 2)->nullable()->after('guests');
            $table->decimal('subtotal', 10, 2)->nullable()->after('nightly_rate');
            $table->decimal('service_fee', 10, 2)->default(0)->after('subtotal');
            $table->decimal('cleaning_fee', 10, 2)->default(0)->after('service_fee');
            $table->decimal('taxes', 10, 2)->default(0)->after('cleaning_fee');
        });

        // Legacy bookings had no fees: the whole total was nights × nightly.
        // Derive a per-night rate from the stored total so the page still adds up.
        DB::statement(
            'UPDATE bookings SET subtotal = total_amount, '.
            'nightly_rate = ROUND(total_amount / GREATEST(DATEDIFF(end_date, start_date), 1), 2) '.
            'WHERE subtotal IS NULL'
        );
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['nightly_rate', 'subtotal', 'service_fee', 'cleaning_fee', 'taxes']);
        });
    }
};
