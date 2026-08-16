<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A company partner's brand logo.
 *
 * Stores a DashboardUpload id (`file_...`) like every other partner file column,
 * so DashboardUpload::resolveUrl() turns it into a public URL and the presign →
 * signed PUT flow is unchanged.
 *
 * Deliberately NOT part of KYC: the column is nullable, nothing reads it for
 * completeness, and a company with no logo is reviewed, approved and paid
 * exactly as before. It is branding, not evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_details', function (Blueprint $table) {
            $table->string('logo_file')->nullable()->after('operator_license_file');
        });
    }

    public function down(): void
    {
        Schema::table('partner_details', fn (Blueprint $table) => $table->dropColumn('logo_file'));
    }
};
