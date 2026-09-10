<?php

use App\Support\Units\UnitLicense;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which permit a listing operates under, and how many units that permit covers.
 *
 * The legal rule the multi-unit feature was missing: a private-hospitality
 * permit covers one home, a tourist-facility permit covers a whole property and
 * states how many units are licensed inside it. Only the second may become a
 * building.
 *
 * Both columns are NULLABLE, and every existing row stays NULL. That is not a
 * gap to backfill later — it is the only honest value. Nobody can tell from a
 * database row whether a company's listing was permitted as a facility or an
 * individual's as private hospitality; that is written on a document a human
 * reads. Guessing would attach a legal claim to rows nobody has checked. NULL
 * with a group of one is a completely valid state, and every listing today is
 * exactly that.
 *
 * The CHECK is the only rule expressible per row. The others depend on how many
 * rows share a `unit_group_id`, which no row can see, so they live in
 * UnitLicense — the constraint stops a stray write, the application returns an
 * answer a partner can act on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->enum('license_type', UnitLicense::TYPES)
                ->nullable()->default(null)->after('tourism_permit_no');

            $table->unsignedSmallInteger('licensed_units_count')
                ->nullable()->default(null)->after('license_type');
        });

        // sqlite (the test suite) cannot ALTER in a CHECK; the application rule
        // is enforced by UnitLicense on every path either way.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE units ADD CONSTRAINT chk_units_facility_license_has_count '.
                "CHECK (license_type <> 'tourist_facility' OR licensed_units_count IS NOT NULL)"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE units DROP CONSTRAINT chk_units_facility_license_has_count');
        }

        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn(['license_type', 'licensed_units_count']);
        });
    }
};
