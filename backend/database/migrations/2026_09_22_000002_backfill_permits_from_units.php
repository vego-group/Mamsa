<?php

use App\Support\Permits\PermitBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Every permit that exists as columns on units gets its row. See
 * PermitBackfill for the rules; this only runs it and records the outcome
 * where an operator will find it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $report = PermitBackfill::run();

        Log::info('permits backfilled from units', $report);
    }

    public function down(): void
    {
        // The rows are derived from the units' own columns, which are left in
        // place; dropping the table (previous migration's down) is the reverse.
    }
};
