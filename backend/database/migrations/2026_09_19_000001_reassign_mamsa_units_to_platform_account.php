<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Platform-owned listings point at the platform, not at the person who typed
 * them in.
 *
 * `units.user_id` is NOT NULL, so a Mamsa-owned unit has always had to point at
 * some user. Until now it pointed at the admin who created it. That row is an
 * employee: their personal name appeared on the storefront as the unit's host
 * (verified live on production, unit #34, 2026-09-16), and any query joining
 * revenue to the owner would have credited platform income to a staff member.
 * A review of the 18 places that aggregate by owner found none that did — but
 * that protection was the review, not the schema, and the next feature to join
 * on the owner would have reopened it.
 *
 * This moves every `mamsa_owned` unit to the platform account and records who
 * it moved from, so the history is not lost — the creating admin is still a
 * fact worth keeping, it just was never the owner.
 *
 * Idempotent: a unit already on the platform account is left alone. Reversible
 * only as far as the log: the previous owners are written to the migration log
 * rather than a column, because adding a column to carry a value we are
 * removing on purpose would keep alive the very ambiguity being closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $platform = User::platform();

        $moved = DB::table('units')
            ->where('mamsa_owned', true)
            ->where('user_id', '!=', $platform->id)
            ->get(['id', 'user_id']);

        foreach ($moved as $unit) {
            Log::info('Mamsa-owned unit reassigned to the platform account', [
                'unit_id' => $unit->id,
                'from_user_id' => $unit->user_id,
                'to_user_id' => $platform->id,
            ]);
        }

        DB::table('units')
            ->where('mamsa_owned', true)
            ->where('user_id', '!=', $platform->id)
            ->update(['user_id' => $platform->id]);
    }

    public function down(): void
    {
        // The previous owners are in the log, not in a column. Reversing this
        // is a manual operation against that log — deliberately, because the
        // old state was the bug.
    }
};
