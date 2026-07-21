<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Split display name into first/last (frontend decision 2026-07-21). `name`
 * stays as the concatenation so everything reading it keeps working; these
 * are the authoritative parts the UI collects in separate inputs.
 *
 * Backfill is a naive whitespace split (first token = first name, remainder =
 * last name) — good enough for existing rows; users correct it in settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
        });

        // MySQL-only backfill; sqlite test DB starts empty so it's skipped.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("UPDATE users SET
                first_name = TRIM(SUBSTRING_INDEX(name, ' ', 1)),
                last_name  = TRIM(SUBSTRING(name, LOCATE(' ', name) + 1))
              WHERE name IS NOT NULL AND name <> '' AND first_name IS NULL");

            // Single-token names: last_name would duplicate first_name → null it.
            DB::statement("UPDATE users SET last_name = NULL
              WHERE name NOT LIKE '% %'");
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name']);
        });
    }
};
