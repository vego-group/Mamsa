<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Business rule (backend gaps #3): the platform supports ONLY three unit
 * types — apartment | studio | villa. Purge any legacy sample units of other
 * types (chalet / rest / resort / camp) so no unsupported type can surface on
 * public endpoints. FK cascades clean up images, features, bookings, payments
 * and reviews automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('units')
            ->whereNotIn('unit_type', ['apartment', 'studio', 'villa'])
            ->delete();
    }

    public function down(): void
    {
        // Irreversible data cleanup — nothing to restore.
    }
};
