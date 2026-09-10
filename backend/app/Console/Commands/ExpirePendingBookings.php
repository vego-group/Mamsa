<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;

/**
 * Booking state machine (SRS 5.2): Pending → Cancelled when payment never came.
 *
 * This no longer gates availability. The nights are released the moment
 * `hold_expires_at` passes, because the availability predicate reads that
 * column — so a run that is late, or never happens, costs untidy rows rather
 * than a unit nobody can book. What this still has to do is close the booking,
 * or it sits `pending_payment` forever and shows in the guest's list as though
 * checkout were still open.
 *
 * Selection is by the hold, not by `created_at` age: the hold is what the
 * availability predicate honours, and expiring on a different clock would let a
 * booking be cancelled while it was still holding its nights, or keep one open
 * long after it had stopped.
 */
class ExpirePendingBookings extends Command
{
    protected $signature = 'bookings:expire-pending {--minutes= : Override the hold for rows written before hold_expires_at existed}';

    protected $description = 'Cancel pending bookings that were never paid, releasing their dates';

    public function handle(): int
    {
        // Rows written before the column existed have no hold. They fall back
        // to the old age rule so they can still be closed, rather than staying
        // pending forever because a column was added after they were made.
        $legacyCutoff = now()->subMinutes((int) ($this->option('minutes') ?: config('booking.hold_minutes', 60)));

        $count = Booking::query()
            ->where('status', Booking::STATUS_PENDING)
            ->where(fn ($q) => $q
                ->where('hold_expires_at', '<=', now())
                ->orWhere(fn ($q) => $q
                    ->whereNull('hold_expires_at')
                    ->where('created_at', '<=', $legacyCutoff)))
            // Never expire a booking whose money side is settled or still moving:
            // paid = webhook/verify will confirm it; a Moyasar-attached payment
            // touched in the last 15 min = a 3-DS redirect may still land.
            ->whereDoesntHave('payment', function ($q) {
                $q->where('payment_status', 'paid')
                    ->orWhere(function ($q) {
                        $q->whereNotNull('moyasar_id')
                            ->where('updated_at', '>', now()->subMinutes(15));
                    });
            })
            ->update([
                'status' => Booking::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => 'system',
                'cancellation_reason' => 'انتهت مهلة إتمام الدفع',
            ]);

        $this->info("Expired {$count} unpaid pending booking(s).");

        return self::SUCCESS;
    }
}
