<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The permit's expiry exactly as the partner typed it in Hijri, beside the
 * Gregorian date the system runs on.
 *
 * Permits are printed in Hijri; the consoles convert, and until now only the
 * result of that conversion was kept. Umm al-Qura tables disagree by a day
 * after 2029-08-10, and nobody has established which one the ministry prints
 * with. If it turns out to differ from the frontend's, every stored date is
 * off by a day with nothing to re-derive it from — the fix would be the paper,
 * permit by permit. Keeping the source makes that one command instead.
 *
 * NULL means the date has no Hijri source: it was typed in Gregorian, or it
 * predates this column. That is information, not a gap. The converter library
 * is NOT recorded per row — it is pinned platform-wide and its history lives
 * in the frontend's docs/audit/hijri-converter-pinned.md.
 *
 * Owner decision 2026-09-30. Additive and nullable: nothing reads it to decide
 * anything, and no client has to send it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permits', function (Blueprint $table) {
            $table->string('expires_at_hijri', 50)->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('permits', function (Blueprint $table) {
            $table->dropColumn('expires_at_hijri');
        });
    }
};
