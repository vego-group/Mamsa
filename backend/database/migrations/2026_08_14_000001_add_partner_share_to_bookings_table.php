<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Freeze the partner's share on each booking — contract v2.2 §1.8.
 *
 * The rest of the VAT split already has frozen columns whose meaning maps
 * exactly onto the inclusive model, so nothing is renamed:
 *   total_amount = gross · subtotal = netBase · taxes = vat
 *   commission_amount = commission (on netBase)
 *
 * `partner_share` is the one missing piece, and it is the figure a payout is
 * actually computed from — so it must be stored, not derived at read time. A
 * later change to the commission rate must never re-price an existing booking
 * or, worse, silently change what a partner is owed for a stay already taken.
 *
 * Backfill is exact rather than estimated: partner share is netBase minus the
 * commission that was frozen at creation, both of which are already on the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('partner_share', 10, 2)->default(0)->after('commission_amount');
        });

        // subtotal IS the net base; commission_amount is already frozen per row.
        DB::statement(
            'UPDATE bookings SET partner_share = '
            .'ROUND(COALESCE(subtotal, 0) - COALESCE(commission_amount, 0), 2)'
        );
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('partner_share');
        });
    }
};
