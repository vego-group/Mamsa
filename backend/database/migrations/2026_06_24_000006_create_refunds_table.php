<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per refund/void executed against Moyasar — SRS 1.1 (refunds).
 * Keeps the audit trail of amount, percent, tier and gateway response
 * for every cancellation (NFR-014).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();

            $table->enum('type', ['refund', 'void']);              // SRS 2.3.3
            $table->decimal('amount', 10, 2);                      // SAR returned
            $table->decimal('refund_percent', 5, 2);              // 0..100 applied
            $table->string('tier_label', 60)->nullable();

            $table->enum('status', ['pending', 'succeeded', 'failed'])->default('pending');
            $table->string('moyasar_refund_id')->nullable()->index();
            $table->json('moyasar_response')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
