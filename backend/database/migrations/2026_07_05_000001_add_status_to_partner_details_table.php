<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_details', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'rejected'])
                ->default('pending')->after('cr_number');
            $table->string('rejection_reason', 500)->nullable()->after('status');
            $table->timestamp('reviewed_at')->nullable()->after('rejection_reason');
        });

        // Partners registered before the approval workflow existed are already
        // operating on the platform — grandfather them in as approved.
        DB::table('partner_details')->update([
            'status'      => 'approved',
            'reviewed_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('partner_details', function (Blueprint $table) {
            $table->dropColumn(['status', 'rejection_reason', 'reviewed_at']);
        });
    }
};
