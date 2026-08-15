<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly partner transfers — wallet contract §3.
 *
 * A row is written AFTER the bank transfer has already happened, so there is
 * deliberately no pending/processing/failed state: only `paid`, and `reversed`
 * if it later bounces.
 *
 * `iban_masked` and `bank_name` are frozen copies taken at payout time, not
 * joins to the partner's current account. A partner who changes bank later must
 * still be able to see which account the old money went to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reference')->unique();      // PO-2026-06
            $table->char('period_month', 7);            // YYYY-MM — the month EARNED
            $table->decimal('amount', 12, 2);
            $table->unsignedInteger('bookings_count')->default(0);
            $table->char('currency', 3)->default('SAR');

            // Frozen at payout time — never the current account.
            $table->string('iban_masked', 20);
            $table->string('bank_name')->nullable();

            $table->enum('status', ['paid', 'reversed'])->default('paid');
            $table->timestamp('paid_at');
            $table->string('bank_reference')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason')->nullable();

            $table->foreignId('created_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['partner_user_id', 'paid_at']);
        });

        // Which payout covered a booking. A booking belongs to at most one
        // payout, so this is a column rather than a pivot.
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('payout_id')->nullable()->after('partner_share')
                ->constrained('payouts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payout_id');
        });

        Schema::dropIfExists('payouts');
    }
};
