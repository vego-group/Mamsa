<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Freeze the applied service-fee % and VAT % onto each booking (frontend
 * request 2026-07-18) so "booking details / invoice" screens can show the
 * rate that was in force at booking time even after the superadmin changes
 * the live setting.
 *
 * Legacy rows are backfilled EXACTLY, not guessed: the percents are fully
 * derivable from the already-frozen line items (fee ÷ base). Pre-contract
 * bookings that charged no fees correctly get 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('service_fee_percent', 5, 2)->nullable()->after('service_fee');
            $table->decimal('tax_percent', 5, 2)->nullable()->after('taxes');
        });

        // MySQL-only: backfills real legacy rows, which only exist on the
        // servers — a fresh sqlite test DB has none to fix.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            'UPDATE bookings SET '.
            'service_fee_percent = CASE WHEN subtotal > 0 THEN ROUND(service_fee / subtotal * 100, 2) ELSE 0 END, '.
            'tax_percent = CASE WHEN (subtotal + cleaning_fee + service_fee) > 0 '.
            '  THEN ROUND(taxes / (subtotal + cleaning_fee + service_fee) * 100, 2) ELSE 0 END '.
            'WHERE service_fee_percent IS NULL'
        );
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['service_fee_percent', 'tax_percent']);
        });
    }
};
