<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The commercial-registration DOCUMENT.
 *
 * `cr_number` has always been a typed 10-digit number with no scan behind it,
 * so the admin KYC list rendered السجل التجاري with `fileUrl: null` — the one
 * document proving the company exists, and the reviewer had nothing to open.
 * The VAT certificate and operator licence both had files; the CR did not.
 *
 * Nullable, and deliberately NOT added to either completeness check: every
 * company already registered has no scan, and gating on it would block them
 * from submitting units over a document they were never asked for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_details', function (Blueprint $table) {
            $table->string('cr_file')->nullable()->after('cr_number');
        });
    }

    public function down(): void
    {
        Schema::table('partner_details', fn (Blueprint $table) => $table->dropColumn('cr_file'));
    }
};
