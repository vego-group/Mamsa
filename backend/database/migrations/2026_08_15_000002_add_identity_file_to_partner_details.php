<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Image of the partner's identity document.
 *
 * `national_id` (the number) has always been captured and required at
 * registration, and the admin already reviews it as a document row — but with
 * `fileUrl: null`, because there was nowhere to put the scan. An admin was
 * therefore approving a KYC identity on a typed number alone.
 *
 * Stores a DashboardUpload id (`file_...`), the same convention the other KYC
 * document columns use, so the existing admin resolution and verify/reject
 * flow pick it up with no changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_details', function (Blueprint $table) {
            $table->string('national_id_file')->nullable()->after('national_id');
        });
    }

    public function down(): void
    {
        Schema::table('partner_details', function (Blueprint $table) {
            $table->dropColumn('national_id_file');
        });
    }
};
