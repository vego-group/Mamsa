<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Idempotency marker for the day-before check-in reminder email (email task
// doc §3): the scheduled job only picks bookings where this is still NULL,
// so a re-run (or an overlapping cron) can never send the reminder twice.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('checkin_reminder_sent_at')->nullable()->after('cancellation_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('checkin_reminder_sent_at');
        });
    }
};
