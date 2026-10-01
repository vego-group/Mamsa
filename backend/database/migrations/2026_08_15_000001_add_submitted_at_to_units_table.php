<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a unit was submitted for review.
 *
 * Until now the approvals dashboard measured "average review time" from
 * `created_at` to the decision, because there was nothing better. That counts
 * however long a listing sat in draft as though a reviewer were sitting on it —
 * a unit drafted for a week and approved in an hour reported as a week. The
 * figure is coloured against a 24h/48h SLA in the admin UI, so it was actively
 * misleading.
 *
 * Backfill is deliberately conservative:
 *  - units currently `pending`: their last touch IS the submission, so
 *    `updated_at` is a sound proxy.
 *  - already-decided units: `updated_at` is the DECISION time and the true
 *    submission time is unrecoverable, so it stays NULL rather than being
 *    invented. The average simply excludes them and becomes accurate as new
 *    decisions flow through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('approval_status');
        });

        DB::table('units')->where('approval_status', 'pending')
            ->update(['submitted_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('submitted_at');
        });
    }
};
