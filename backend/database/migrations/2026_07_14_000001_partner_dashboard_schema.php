<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Partner-dashboard contract (v1.2) schema:
 *  - unit_ical_feeds: named multi-feed iCal import per unit (§5.4) — replaces
 *    the single units.ical_import_url (kept for the Vue partner UI; migrated
 *    into a feed row here).
 *  - partner_details: company payout docs (§9.2).
 *  - dashboard_uploads: presigned-style upload references (§9.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_ical_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->string('source', 50);              // "Airbnb", "Booking.com"…
            $table->string('url', 2048);
            $table->string('status', 20)->default('pending'); // pending|synced|error
            $table->string('error', 500)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['unit_id', 'status']);
        });

        // Attribute imported blocked-date rows to their feed so a per-feed
        // re-sync only touches its own rows (a broken feed keeps last snapshot).
        // Plain indexed column (no DB-level FK): SQLite can't ALTER-ADD a
        // constraint, and feed deletion already clears its rows in code.
        Schema::table('unit_blocked_dates', function (Blueprint $table) {
            $table->unsignedBigInteger('ical_feed_id')->nullable()->after('source');
            $table->index('ical_feed_id');
        });

        // Existing single-URL imports become the unit's first named feed, and
        // their already-synced blocked rows are re-attributed to that feed.
        foreach (DB::table('units')->whereNotNull('ical_import_url')->get(['id', 'ical_import_url', 'ical_synced_at']) as $unit) {
            $feedId = DB::table('unit_ical_feeds')->insertGetId([
                'unit_id'        => $unit->id,
                'source'         => 'خارجي',
                'url'            => $unit->ical_import_url,
                'status'         => $unit->ical_synced_at ? 'synced' : 'pending',
                'last_synced_at' => $unit->ical_synced_at,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            DB::table('unit_blocked_dates')
                ->where('unit_id', $unit->id)
                ->where('source', 'ical')
                ->update(['ical_feed_id' => $feedId]);
        }

        Schema::table('units', function (Blueprint $table) {
            // Contract §4 — free-text street address (distinct from city/district).
            $table->string('address', 255)->nullable()->after('district');
        });

        Schema::table('partner_details', function (Blueprint $table) {
            $table->string('iban', 34)->nullable()->after('cr_number');
            $table->string('authorization_letter_file')->nullable()->after('iban');
            $table->string('vat_certificate_file')->nullable()->after('authorization_letter_file');
            $table->string('operator_license_file')->nullable()->after('vat_certificate_file');
        });

        Schema::create('dashboard_uploads', function (Blueprint $table) {
            $table->string('id', 40)->primary();       // "file_" + ulid
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);                // unit_photo|license_pdf|company_doc
            $table->string('original_name');
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('path')->nullable();        // set once the PUT lands
            $table->string('status', 20)->default('pending'); // pending|stored
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_uploads');
        Schema::table('partner_details', function (Blueprint $table) {
            $table->dropColumn(['iban', 'authorization_letter_file', 'vat_certificate_file', 'operator_license_file']);
        });
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('address');
        });
        Schema::table('unit_blocked_dates', function (Blueprint $table) {
            $table->dropColumn('ical_feed_id');
        });
        Schema::dropIfExists('unit_ical_feeds');
    }
};
