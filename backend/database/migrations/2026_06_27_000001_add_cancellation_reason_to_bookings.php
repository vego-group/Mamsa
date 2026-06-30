<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cancellation outcome metadata for the "الحجز ملغي" card (SRS 5.2).
 * Kept separate from `cancellation_snapshot` — that column freezes the *policy*
 * at payment time (FR-036) and must never be overwritten by the cancellation.
 * - cancellation_reason: free text the guest gives when cancelling.
 * - cancelled_by: who triggered it — customer | admin | system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_at');
            $table->string('cancelled_by', 20)->nullable()->after('cancellation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['cancellation_reason', 'cancelled_by']);
        });
    }
};
