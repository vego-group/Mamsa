<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-run the bathrooms/area backfill for units seeded AFTER the original
 * add_specs migration ran (those rows are created with null dimensions, e.g.
 * production unit 14). Uses the same derivation as that migration and only
 * touches null columns, so the detail page never shows blanks.
 */
return new class extends Migration
{
    public function up(): void
    {
        // CASE WHEN, not GREATEST() — sqlite (the test DB) has no GREATEST, and
        // the raw expression is parsed even when no rows match.
        DB::table('units')->whereNull('bathrooms')
            ->update(['bathrooms' => DB::raw('CASE WHEN bedrooms > 1 THEN bedrooms ELSE 1 END')]);

        DB::table('units')->whereNull('area')
            ->update(['area' => DB::raw('(bedrooms * 60 + capacity * 15 + 40)')]);
    }

    public function down(): void
    {
        // Backfill only — nothing to reverse.
    }
};
