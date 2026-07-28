<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive columns the admin-panel (Next.js) BFF needs for its Phase-5
 * mutations — BACKEND_SPEC §5.4–5.6, §5.9. All nullable / defaulted so nothing
 * in /api/v1 or testvue is affected.
 *
 *  - users.invited_at            → distinguishes "pending_activation" (invited,
 *                                  not yet active) from a plain "disabled" user.
 *  - partner_details.verified_at → the trust badge, independent of KYC status
 *                                  (verify / revoke-verification endpoints).
 *  - partner_details.suspension_reason → recorded on suspend.
 *  - partner_details.verified_documents → JSON list of individually-verified
 *                                  KYC document kinds.
 *  - units.mamsa_owned           → a platform-owned listing (create-unit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('invited_at')->nullable()->after('is_active');
        });

        Schema::table('partner_details', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable()->after('status');
            $table->string('suspension_reason', 500)->nullable()->after('rejection_reason');
            $table->json('verified_documents')->nullable()->after('suspension_reason');
        });

        Schema::table('units', function (Blueprint $table) {
            $table->boolean('mamsa_owned')->default(false)->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('invited_at'));
        Schema::table('partner_details', fn (Blueprint $table) => $table->dropColumn(['verified_at', 'suspension_reason', 'verified_documents']));
        Schema::table('units', fn (Blueprint $table) => $table->dropColumn('mamsa_owned'));
    }
};
