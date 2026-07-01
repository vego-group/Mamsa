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
        DB::table('units')->whereNull('bathrooms')
            ->update(['bathrooms' => DB::raw('GREATEST(1, bedrooms)')]);

        DB::table('units')->whereNull('area')
            ->update(['area' => DB::raw('(bedrooms * 60 + capacity * 15 + 40)')]);
    }

    public function down(): void
    {
        // Backfill only — nothing to reverse.
    }
};
