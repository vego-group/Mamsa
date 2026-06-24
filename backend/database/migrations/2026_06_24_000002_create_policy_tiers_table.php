<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable refund tiers per policy template — SRS 2.3.1 (NFR-013).
 * A tier matches when (hours before check-in) >= min_hours_before_checkin;
 * the highest matching threshold wins. Editing these rows re-tunes refunds
 * with no code change — but never affects an already-confirmed booking,
 * because each booking freezes its own snapshot (FR-036).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cancellation_policy_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('min_hours_before_checkin'); // e.g. 168 (7d), 72 (3d), 0
            $table->unsignedTinyInteger('refund_percent');            // 0..100
            $table->string('label_ar', 60);

            $table->timestamps();

            $table->unique(['cancellation_policy_id', 'min_hours_before_checkin'], 'policy_tier_threshold_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_tiers');
    }
};
