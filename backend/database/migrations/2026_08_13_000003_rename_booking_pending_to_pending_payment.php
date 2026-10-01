<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rename the booking status `pending` → `pending_payment`.
 *
 * `pending` was ambiguous: this platform has unit approval and partner approval
 * as separate concepts, so a bare `pending` reads as "awaiting admin approval".
 * `pending_payment` states what it is — an unpaid booking awaiting payment, the
 * literal all three frontends already assert (the admin BFF previously faked it
 * with a translation shim, now deleted).
 *
 * Pre-launch: every booking is demo data, so this is a value rename, not a
 * data-loss risk. Widen the enum → migrate rows → narrow it, so no row is ever
 * invalid mid-flight. SQLite stores enums as TEXT (no constraint to alter).
 *
 * Only `bookings.status` changes. The identically-named `pending` on
 * partner_details.status, units.approval_status, wallet_transactions.status,
 * refunds.status and dashboard_uploads.status are DIFFERENT concepts — untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            // Widen so both the old and new value are legal during the update.
            DB::statement(
                "ALTER TABLE bookings MODIFY status "
                ."ENUM('pending','pending_payment','confirmed','cancelled','completed') "
                ."NOT NULL DEFAULT 'pending'"
            );
        } elseif ($driver === 'sqlite') {
            // sqlite's enum() emits a CHECK constraint, and the earlier
            // "add completed" migration was MySQL-only — so the constraint here
            // is already stale (it never gained 'completed'). Drop it by making
            // the column a plain string: the legal value set is enforced by the
            // application and by the MySQL enum, which is the source of truth.
            Schema::table('bookings', function (Blueprint $table) {
                $table->string('status')->default('pending_payment')->change();
            });
        }

        DB::table('bookings')->where('status', 'pending')->update(['status' => 'pending_payment']);

        if ($driver === 'mysql') {
            DB::statement(
                "ALTER TABLE bookings MODIFY status "
                ."ENUM('pending_payment','confirmed','cancelled','completed') "
                ."NOT NULL DEFAULT 'pending_payment'"
            );
        }
    }

    public function down(): void
    {
        $mysql = DB::getDriverName() === 'mysql';

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('bookings', function (Blueprint $table) {
                $table->string('status')->default('pending')->change();
            });
        }

        if ($mysql) {
            DB::statement(
                "ALTER TABLE bookings MODIFY status "
                ."ENUM('pending','pending_payment','confirmed','cancelled','completed') "
                ."NOT NULL DEFAULT 'pending'"
            );
        }

        DB::table('bookings')->where('status', 'pending_payment')->update(['status' => 'pending']);

        if ($mysql) {
            DB::statement(
                "ALTER TABLE bookings MODIFY status "
                ."ENUM('pending','confirmed','cancelled','completed') "
                ."NOT NULL DEFAULT 'pending'"
            );
        }
    }
};
