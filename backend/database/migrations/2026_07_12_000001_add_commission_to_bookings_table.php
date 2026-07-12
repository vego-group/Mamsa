<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Frozen at creation like the rest of the price breakdown —
            // changing config('booking.commission_rate') later never
            // re-prices an existing booking.
            $table->decimal('commission_rate', 5, 4)->default(0)->after('taxes');
            $table->decimal('commission_amount', 10, 2)->default(0)->after('commission_rate');
        });

        // Backfill: the 2% rule applies to every booking ever made (approved
        // by the business owner — no partner payouts predate this migration).
        $rate = (float) config('booking.commission_rate');

        DB::table('bookings')->update([
            'commission_rate'   => $rate,
            'commission_amount' => DB::raw("ROUND(subtotal * {$rate}, 2)"),
        ]);
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['commission_rate', 'commission_amount']);
        });
    }
};
