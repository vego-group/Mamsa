<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per reminder actually sent, so none is ever sent twice.
 *
 * The daily job asks "which permits are 30 days out?" and the answer is the
 * same every time it runs that day — a retry, a second cron entry, a manual
 * run while debugging. Without a record, a partner gets the same warning three
 * times and starts ignoring the fourth. The UNIQUE(permit_id, threshold) is
 * what makes that impossible rather than unlikely: the insert is the claim, so
 * two concurrent runs cannot both decide they are the one sending it.
 *
 * Keyed on the permit, not the unit: in a building one permit covers every
 * door, and the partner should hear once about the building, not once per
 * apartment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permit_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permit_id')->constrained('permits')->cascadeOnDelete();
            // Days before expiry: 60, 30, 14, 7, 1, and 0 for the day itself.
            $table->unsignedSmallInteger('threshold');
            // The expiry this reminder was about. A renewal moves the date, and
            // the same permit row may then warn again for the new one — so the
            // key includes neither this nor the date, and a renewal instead
            // clears the rows it has made obsolete.
            $table->date('expires_at');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['permit_id', 'threshold'], 'permit_reminders_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permit_reminders');
    }
};
