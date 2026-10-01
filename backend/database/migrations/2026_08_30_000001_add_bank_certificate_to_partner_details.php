<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank account proof — an IBAN letter or account certificate from the bank.
 *
 * The admin review screen has always shown a "توثيق الحساب البنكي" row, but it
 * was derived from `iban` alone: a typed number, with nothing behind it to
 * check. A reviewer approving a payout destination had no document to look at.
 *
 * Lives on partner_details, not units: a bank account belongs to the partner
 * and is the same one for every listing they own. Uploading it per unit would
 * store the same certificate many times and let the copies disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_details', function (Blueprint $table) {
            $table->string('bank_certificate_file')->nullable()->after('iban');
        });
    }

    public function down(): void
    {
        Schema::table('partner_details', function (Blueprint $table) {
            $table->dropColumn('bank_certificate_file');
        });
    }
};
