<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Availability calendar (anti double-booking, phase 1+2).
 *
 * `unit_blocked_dates` holds date ranges a unit cannot be booked:
 *   - source=manual : partner closed the dates from the dashboard
 *   - source=ical   : imported from an external platform calendar (Booking,
 *                     Airbnb…) by the calendar:sync command. Rows are replaced
 *                     wholesale on every sync, keyed by external_uid.
 *
 * `units.ical_import_url` is the partner-provided external .ics feed;
 * `units.ical_synced_at` records the last successful pull.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_blocked_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();

            $table->date('start_date');
            $table->date('end_date');            // exclusive, iCal DTEND convention
            $table->enum('source', ['manual', 'ical'])->default('manual');
            $table->string('note')->nullable();  // e.g. "صيانة" or the external SUMMARY
            $table->string('external_uid')->nullable(); // iCal UID for idempotent re-sync

            $table->timestamps();

            $table->index(['unit_id', 'start_date', 'end_date']);
            $table->index(['unit_id', 'source']);
        });

        Schema::table('units', function (Blueprint $table) {
            $table->string('ical_import_url', 2048)->nullable()->after('calendar_token');
            $table->timestamp('ical_synced_at')->nullable()->after('ical_import_url');
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn(['ical_import_url', 'ical_synced_at']);
        });

        Schema::dropIfExists('unit_blocked_dates');
    }
};
