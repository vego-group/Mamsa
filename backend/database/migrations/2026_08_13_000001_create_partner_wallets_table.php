<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partner earnings wallet — contract v2.2 §2 (one row per partner).
 *
 * Distinct from the guest `wallet_transactions` history (that stays as-is); the
 * partner ledger is `partner_ledger_entries`. Balances are backend-owned.
 *
 * `available_balance` is a SIGNED decimal — no UNSIGNED, no CHECK (>= 0):
 * contract §2.2 requires a refund_reversal / adjustment to be able to drive it
 * negative and carry that forward, never clamped to zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_user_id')->unique()->constrained('users')->cascadeOnDelete();

            $table->decimal('available_balance', 12, 2)->default(0); // signed — may go negative
            $table->decimal('pending_balance', 12, 2)->default(0);
            $table->decimal('lifetime_earnings', 12, 2)->default(0);
            $table->decimal('lifetime_paid_out', 12, 2)->default(0);
            $table->char('currency', 3)->default('SAR');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_wallets');
    }
};
