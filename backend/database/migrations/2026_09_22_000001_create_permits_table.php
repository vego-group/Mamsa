<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A permit becomes a row of its own.
 *
 * Until now the tourism permit was four columns copied onto every unit it
 * covered — number, file, licence type, licensed count — and a building of
 * eight apartments held eight copies of the same permit. That was workable
 * while a permit was two facts. It stops being workable the moment a permit
 * has a life of its own: an expiry date, an address printed on it, a renewal
 * that must sit BESIDE the current permit while a reviewer looks at it, and a
 * number that may belong to exactly one listing on the platform. None of
 * those fit on the unit row; the renewal cannot fit there at all.
 *
 * Scope: a permit covers either one unit (a private-hospitality permit, or a
 * facility permit on a standalone listing) or one whole group (a facility
 * permit issued to the building). `scope_type` + `scope_id` say which.
 * `scope_id` is a string because a group's id is a ULID label, not a row.
 *
 * The four columns on `units` STAY, as read copies of the scope's `current`
 * permit, so nothing that reads them changes; they are written by the permit
 * writer alone (see App\Support\Permits\PermitWriter and Unit::booted()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permits', function (Blueprint $table) {
            $table->id();
            $table->enum('scope_type', ['unit', 'group']);
            $table->string('scope_id', 26);
            // Normalised (see PermitNumber): trimmed, ASCII digits, no spaces
            // or commas, upper-case. Never compared or stored any other way.
            $table->string('number', 64)->nullable();
            // An upload id (`file_…`) or, for rows older than the upload table,
            // a bare storage path — the same two shapes units.tourism_permit_file
            // has always held, resolved by DocumentStorage::locate().
            $table->string('file', 255)->nullable();
            $table->enum('license_type', ['tourist_facility', 'private_hospitality'])->nullable();
            $table->unsignedSmallInteger('licensed_units_count')->nullable();
            // NULL for every permit that predates this column. Never guessed.
            $table->date('expires_at')->nullable();
            // The address printed on the permit, as structured fields, for the
            // reviewer to set beside the listing's own address.
            $table->string('addr_city', 100)->nullable();
            $table->string('addr_district', 150)->nullable();
            $table->string('addr_building', 50)->nullable();
            $table->string('addr_unit_no', 50)->nullable();
            // current: the permit the listing trades under. pending: a renewal
            // awaiting review. rejected / superseded: history, kept.
            $table->enum('status', ['current', 'pending', 'rejected', 'superseded'])->default('current');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->index(['scope_type', 'scope_id', 'status'], 'permits_scope_status_index');
            $table->index('number');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permits');
    }
};
