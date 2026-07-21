<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Number of beds (عدد الأسرّة) — distinct from bedrooms (عدد الغرف). A studio
 * can have 0 bedrooms but 2 beds; a 2-bedroom unit may hold 3 beds. Nullable
 * so existing rows and drafts aren't forced to backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->unsignedTinyInteger('beds')->nullable()->after('bedrooms');
        });

        // Sensible default for existing rows: at least one bed per bedroom
        // (min 1), so old units don't render "0 beds" until partners edit.
        DB::table('units')->whereNull('beds')->update([
            'beds' => DB::raw('CASE WHEN bedrooms > 0 THEN bedrooms ELSE 1 END'),
        ]);
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('beds');
        });
    }
};
