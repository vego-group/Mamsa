<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photos attached to a complaint — spec v1.0 §4.2.
 *
 * `path` points at a PRIVATE disk. These are photographs of someone's rented
 * home, submitted in a dispute; a public URL would be guessable and permanent.
 * Admin surfaces hand out signed URLs with a 15-minute expiry instead, so a
 * link pasted into a chat stops working on its own.
 *
 * Max 6 per complaint and 5 MB each are request-level rules, not columns —
 * a DB constraint could not produce the Arabic validation message the guest
 * needs, and the limit is a product decision that will move.
 *
 * created_at only: an attachment is written once and never edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_complaint_attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('complaint_id')
                ->constrained('booking_complaints')
                ->cascadeOnDelete();

            $table->string('path');          // private disk, never a public URL
            $table->string('mime', 40);      // image/jpeg | image/png | image/webp
            $table->unsignedInteger('size_bytes');

            $table->timestamp('created_at')->nullable();

            $table->index('complaint_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_complaint_attachments');
    }
};
