<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a unit to a cancellation policy template (SRS 1.1 — units FK to
 * cancellation_policy). The legacy enum column is kept for backward
 * compatibility with the existing UI and is no longer the source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->foreignId('cancellation_policy_id')
                  ->nullable()
                  ->after('cancellation_policy')
                  ->constrained()
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancellation_policy_id');
        });
    }
};
