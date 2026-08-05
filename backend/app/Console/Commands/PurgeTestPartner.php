<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Support\PhoneNumber;
use App\Support\TestMode;
use Illuminate\Console\Command;

/**
 * Remove ALL sample data created for the test partner by `test-partner:populate`
 * — units and everything that cascades from them (bookings, payments, images,
 * iCal feeds, manual blocks) plus the partner's notifications. Reverts the admin
 * platform revenue/analytics that the --rich bookings contributed.
 *
 * The partner LOGIN account itself is kept (only its data is removed). Guarded to
 * test-mode phones unless --force, so it can never nuke a real partner's listings.
 *
 *   php artisan test-partner:purge
 *   php artisan test-partner:purge --phone=+9665XXXXXXXX --force
 */
class PurgeTestPartner extends Command
{
    protected $signature = 'test-partner:purge
        {--phone= : Partner phone (defaults to config test_mode.accounts.partner)}
        {--force : Allow purging a phone that is NOT a test-mode account}';

    protected $description = 'Delete the test partner\'s sample units + bookings/payments (reverts admin revenue)';

    public function handle(): int
    {
        $raw = (string) ($this->option('phone') ?: config('test_mode.accounts.partner'));

        if (trim($raw) === '') {
            $this->error('No partner phone: pass --phone or set TEST_PARTNER_PHONE.');

            return self::FAILURE;
        }

        $phone = PhoneNumber::toE164Ksa($raw);

        if (! TestMode::isTestPhone($phone) && ! $this->option('force')) {
            $this->error("Refusing: {$phone} is not a test-mode account. Re-run with --force if you are sure.");

            return self::FAILURE;
        }

        $partner = User::where('phone', $phone)->first();

        if (! $partner) {
            $this->info("No user for {$phone} — nothing to purge.");

            return self::SUCCESS;
        }

        $unitIds = $partner->units()->pluck('id');

        if ($unitIds->isEmpty()) {
            $this->info("Partner {$partner->phone} has no units — nothing to purge.");

            return self::SUCCESS;
        }

        // Count before deleting (bookings/payments go away by DB cascade on units).
        $bookingIds = Booking::whereIn('unit_id', $unitIds)->pluck('id');
        $bookings = $bookingIds->count();
        $payments = Payment::whereIn('booking_id', $bookingIds)->count();

        // Detach the many-to-many amenities first (no ON DELETE cascade guarantee),
        // then delete the units — FK cascades remove bookings → payments, images,
        // iCal feeds and manual blocks at the DB level.
        $partner->units()->get()->each(fn ($u) => $u->features()->detach());
        $units = $partner->units()->delete();

        // The partner's own notification feed (best-effort).
        $notifications = $partner->notifications()->delete();

        $this->newLine();
        $this->line("  Purged test partner: {$partner->name} ({$partner->phone})");
        $this->table(
            ['Units', 'Bookings', 'Payments', 'Notifications'],
            [[$units, $bookings, $payments, $notifications]],
        );
        $this->info('  Admin platform revenue/analytics reverted. Login account kept.');

        return self::SUCCESS;
    }
}
