<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-unit buildings: 100 apartments, one spec, one listing.
 *
 * A partner with 100 identical apartments in one tower needs 100 BOOKABLE
 * things, not one thing with a counter. A `quantity` column could say how many
 * are free but never which one the guest is in, could not close 402 for
 * maintenance without arbitrarily shrinking the pool, and would mean rewriting
 * the double-booking guard in BookingController — which serialises on the unit
 * row and is proven under concurrency. A hundred real rows keep all of that
 * working untouched: each apartment queues on its own row.
 *
 * What the rows then need is a way to be understood as one listing:
 *
 *   unit_group_id  a ULID shared by every apartment cloned from the same
 *                  source. Deliberately NOT a foreign key to another unit —
 *                  keying the group off the first unit's id would make
 *                  deleting that one apartment orphan (or cascade away) the
 *                  other 99. A group is a label, not a parent.
 *
 *   apartment_no   what the door says: "402", "B-12". `code` is the system's
 *                  own random reference and stays that.
 *
 * Both nullable: a standalone unit has no group and no apartment number, and
 * every existing row is exactly that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->ulid('unit_group_id')->nullable()->after('code');
            $table->string('apartment_no', 20)->nullable()->after('unit_group_id');

            // Listing collapse and group allocation both read "every unit in
            // this group", so that lookup is the one that has to be indexed.
            $table->index('unit_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropIndex(['unit_group_id']);
            $table->dropColumn(['unit_group_id', 'apartment_no']);
        });
    }
};
