<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decision (2026-07-18, supersedes the morning's pricing contract):
 * cleaning fee and service fee are abolished — the guest price is
 * subtotal + 15% VAT, nothing else. Commission (partner-side) is unchanged.
 *
 * Drops the per-unit cleaning fee and the platform_settings store (its only
 * key was service_fee_percent). The bookings fee columns are KEPT on purpose:
 * 62 prod bookings (2026-06-30 → 07-06) charged real fees — their frozen
 * breakdowns are financial history and must keep summing to total_amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('cleaning_fee');
        });

        Schema::dropIfExists('platform_settings');
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->decimal('cleaning_fee', 8, 2)->default(0)->after('price');
        });

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('value');
            $table->timestamps();
        });
    }
};
