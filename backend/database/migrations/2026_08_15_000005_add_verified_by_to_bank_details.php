<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who approved this payout destination.
 *
 * Verification is the control that lets money leave the platform toward a
 * specific account number, so "when" alone is not enough of an audit trail —
 * a disputed transfer needs to name the person who approved the destination.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_details', function (Blueprint $table) {
            $table->foreignId('verified_by_admin_id')->nullable()->after('verified_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_details', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by_admin_id');
        });
    }
};
