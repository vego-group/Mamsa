<?php

declare(strict_types=1);

use App\Models\Unit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `units.checkout_time` becomes NOT NULL, defaulting to 12:00.
 *
 * The column was nullable, and five staging units carried NULL. That was
 * harmless while the value only rendered on a listing page, and stopped being
 * harmless once the complaint window began closing 48 hours after check-out:
 * an absent time has to be assumed, and assuming midnight cuts the guest's
 * deadline from 48 hours to 36 — on exactly the units we know least about.
 *
 * A read-time fallback answered that, but a fallback is a guess repeated at
 * every call site, and the next caller may guess differently. This makes the
 * value a fact of the row instead, so nothing downstream has to decide what an
 * absent check-out time means.
 *
 * The backfill runs inside the migration because the constraint cannot be added
 * over existing NULLs — putting it here means the deploy order cannot get it
 * wrong. Production already has none; staging has five.
 */
return new class extends Migration
{
    public function up(): void
    {
        $filled = DB::table('units')
            ->whereNull('checkout_time')
            ->update(['checkout_time' => Unit::DEFAULT_CHECKOUT_TIME]);

        if ($filled > 0) {
            info("checkout_time backfilled on {$filled} unit(s) → ".Unit::DEFAULT_CHECKOUT_TIME);
        }

        Schema::table('units', function (Blueprint $table) {
            $table->time('checkout_time')->default(Unit::DEFAULT_CHECKOUT_TIME)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        // Only the constraint is lifted. The backfilled values stay: they are
        // now these listings' actual check-out times, and re-nulling them for
        // the sake of symmetry would reintroduce the ambiguity.
        Schema::table('units', function (Blueprint $table) {
            $table->time('checkout_time')->nullable()->change();
        });
    }
};
