<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cancellation policy templates (flexible / moderate / strict) — SRS 2.3.1.
 * Templates are seeded but their tier values live in policy_tiers and stay
 * Configurable (NFR-013) so finance can tune refunds without a code change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancellation_policies', function (Blueprint $table) {
            $table->id();
            $table->string('key', 30)->unique();        // flexible | moderate | strict
            $table->string('name_ar', 60);
            $table->string('name_en', 60);
            $table->string('description', 255)->nullable();
            $table->boolean('is_default')->default(false); // default template for new units
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cancellation_policies');
    }
};
