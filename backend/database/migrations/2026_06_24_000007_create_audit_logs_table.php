<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable audit trail — SRS 1.1 (audit_logs) / NFR-014.
 * Records every sensitive state transition (unit lifecycle, booking
 * cancellation, refund) with before/after diffs and the acting user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->morphs('auditable');                 // auditable_type + auditable_id (indexed)
            $table->string('action', 60);                // e.g. booking.cancelled, refund.executed
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
